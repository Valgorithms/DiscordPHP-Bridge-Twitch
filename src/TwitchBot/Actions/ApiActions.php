<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-TwitchBot project.
 *
 * Copyright (c) 2026-present Valithor Obsidion <valithor@valgorithms.com>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace TwitchBot\Actions;

use React\Promise\PromiseInterface;
use TwitchBot\Api\Sensitive;
use TwitchBot\Command\Access;
use TwitchBot\Command\Action;
use TwitchBot\Command\ActionError;
use TwitchBot\Command\ActionProvider;
use TwitchBot\Command\Arguments;
use TwitchBot\Command\Context;
use TwitchBot\Command\Slash;
use TwitchBot\Command\SlashOption;
use TwitchBot\Command\Surface;
use TwitchBot\Support\Format;

/**
 * `api` — the rest of Helix.
 *
 * The named commands elsewhere in this directory cover what a chat actually
 * asks for day to day. This covers everything else: every method of every
 * TwitchPHP repository, reachable by name, with arguments matched to parameters
 * and the response rendered back into chat.
 *
 * ```
 * api list                                  every repository
 * api list moderation                       every call on one
 * api help moderation.warn                  its signature
 * api moderation.warn broadcaster_id=1 moderator_id=2 user_id=3 reason=spam
 * ```
 *
 * Gated to {@see Access::Owner} — not to the broadcaster, and not to moderators.
 * This reaches endpoints that ban users, end streams and rewrite a channel, and
 * it does so with the bot's own token; the rung below it is a badge anyone can
 * be given by someone who is not thinking about that.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class ApiActions implements ActionProvider
{
    /** Rendered responses are trimmed to this before the surface's own limit. */
    private const MAX_RENDER = 1_500;

    public function actions(): array
    {
        return [
            new Action(
                'api',
                $this->api(...),
                'Call any Twitch endpoint directly',
                'list [repository] | help <call> | <repository.method> key=value ...',
                access: Access::Owner,
                group: 'api',
                // Typed options cannot express "any number of arbitrary
                // key=value pairs", so the arguments arrive as one string and
                // are parsed by {@see namedFor()} exactly as the prefix form
                // parses them. Ephemeral: responses can be long and are of
                // interest to one person.
                slash: new Slash([
                    new SlashOption('call', 'repository.method, e.g. channels.modify', SlashOption::STRING, true),
                    new SlashOption('args', 'key=value pairs, e.g. broadcaster_id=123 first=5'),
                ], ephemeral: true),
            ),
        ];
    }

    private function api(Context $context, Arguments $arguments): PromiseInterface|string
    {
        $first = strtolower((string) $arguments->get(0, ''));

        return match ($first) {
            '' => 'usage: `api list`, `api help <call>`, or `api <repository.method> key=value ...`',
            'list', 'ls' => $this->list($context, $arguments),
            'help', 'usage', 'sig' => $this->help($context, $arguments),
            default => $this->call($context, $arguments),
        };
    }

    private function list(Context $context, Arguments $arguments): string
    {
        $dispatcher = $context->bot->getDispatcher();
        $which = (string) $arguments->get(1, '');

        if ($which === '') {
            return Format::listing(
                $context->surface,
                $dispatcher->repositories(),
                sprintf('%d repositories', count($dispatcher->repositories())),
            );
        }

        $methods = $dispatcher->methods($which);

        return Format::listing(
            $context->surface,
            $methods,
            sprintf('%s (%d calls)', $which, count($methods)),
        );
    }

    private function help(Context $context, Arguments $arguments): string
    {
        $call = (string) $arguments->get(1, '');

        if ($call === '') {
            throw new ActionError('help with what? `api help channels.modify`');
        }

        $signature = $context->bot->getDispatcher()->signature($call);
        $note = Sensitive::isSecret($call)
            ? ' — this one returns a secret, so it is answered by DM and refused on Twitch.'
            : '';

        return '`' . $signature . '`' . $note;
    }

    /** @return PromiseInterface<string> */
    private function call(Context $context, Arguments $arguments): PromiseInterface
    {
        $call = (string) $arguments->get(0, '');

        // A secret-returning call is refused wherever it could be overheard.
        // `api` is not flagged sensitive wholesale, because almost none of it
        // is and that would take the whole command off Twitch; the check is
        // per-call instead. On Twitch `isPublic` is always true, so this always
        // refuses there — which is correct: chat has no private corner.
        if (Sensitive::isSecret($call) && $context->isPublic) {
            throw new ActionError(sprintf(
                '`%s` returns a credential. Run it in a DM with me, not in a channel.',
                $call,
            ));
        }

        return $context->bot->getDispatcher()->call($call, $this->namedFor($arguments))->then(
            fn (mixed $result): string => $this->render($context, $call, $result),
        );
    }

    /**
     * The `key=value` arguments for the call.
     *
     * The prefix form parses them out of the line already. The slash form
     * cannot — Discord has no option type meaning "arbitrary pairs" — so they
     * arrive as one `args` string and are parsed here with the same parser, so
     * both forms accept identical syntax, quoting included.
     *
     * @return array<string, string>
     */
    private function namedFor(Arguments $arguments): array
    {
        $raw = $arguments->named('args');

        if ($raw === null) {
            return $arguments->allNamed();
        }

        // `call` and `args` are this command's own options, not the endpoint's.
        $named = Arguments::fromString($raw)->allNamed();
        unset($named['call'], $named['args']);

        return $named;
    }

    /**
     * Renders whatever a repository handed back.
     *
     * Everything is passed through {@see Sensitive::redact()} first — not only
     * the calls on the denylist. That list is a judgement about today's API and
     * will age; the field-level pass is what catches the endpoint nobody
     * thought about, including ones added to TwitchPHP after this was written.
     */
    private function render(Context $context, string $call, mixed $result): string
    {
        $data = Sensitive::redact($this->normalize($result));

        if ($data === null || $data === []) {
            return sprintf('`%s` returned nothing (which for most writes means it worked).', $call);
        }

        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        if ($json === false) {
            return sprintf('`%s` returned something that will not encode as JSON.', $call);
        }

        // Twitch cannot render a code block and has 500 characters to play
        // with, so it gets a compact one-liner instead of pretty-printed JSON
        // that would be cut off after the opening brace.
        if ($context->surface === Surface::Twitch) {
            $compact = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            return Format::clamp((string) $compact, $context->surface);
        }

        return Format::code($context->surface, substr($json, 0, self::MAX_RENDER), 'json');
    }

    /**
     * Flattens Parts and Collections into plain arrays.
     *
     * Repositories return rich objects; `json_encode` on one of those would
     * either expose internals or produce `{}`, depending on the class.
     */
    private function normalize(mixed $result): mixed
    {
        if ($result === null || is_scalar($result)) {
            return $result;
        }

        if ($result instanceof \JsonSerializable) {
            return $result->jsonSerialize();
        }

        if (is_iterable($result)) {
            $rows = [];

            foreach ($result as $key => $item) {
                $rows[$key] = $this->normalize($item);
            }

            return $rows;
        }

        if (is_object($result)) {
            return method_exists($result, 'getRawAttributes')
                ? $result->getRawAttributes()
                : get_object_vars($result);
        }

        return $result;
    }
}
