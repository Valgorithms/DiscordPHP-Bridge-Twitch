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

namespace TwitchBot\Command;

use Discord\Builders\CommandBuilder;
use Discord\Builders\MessageBuilder;
use Discord\Parts\Interactions\Command\Command;
use Discord\Parts\Interactions\Command\Option as DiscordOption;
use Discord\Parts\Interactions\Interaction;
use Discord\Parts\OAuth\Application;
use Discord\Repository\Interaction\GlobalCommandRepository;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

use TwitchBot\Bot;
use TwitchBot\Support\Format;
use TwitchBot\Support\MessageText;
use TwitchBot\Support\Permissions;

/**
 * Registers actions that carry a {@see Slash} spec as Discord slash commands.
 *
 * The third surface, and the one that reuses the most: an action's handler is
 * not touched at all. Discord's typed options are rendered back into exactly
 * the text the prefix form would have produced — a channel picker becomes
 * `<#id>` — so `/relay link twitch:x` and `!relay link x` run the same code
 * down to the argument indices.
 *
 * Slash commands matter beyond convenience. Message Content is a privileged
 * intent; a server that has not granted it gets no prefix commands at all, but
 * slash commands keep working, because Discord delivers the arguments rather
 * than the bot reading them out of a message.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class SlashAdapter
{
    public function __construct(
        private readonly Bot $bot,
        private readonly ActionRegistry $registry,
    ) {
    }

    /** Defines the commands with Discord, and starts listening for them. */
    public function register(): void
    {
        $actions = $this->slashActions();

        if ($actions === []) {
            return;
        }

        foreach ($actions as $action) {
            $this->bot->listenCommand($action->name, fn (Interaction $interaction) => $this->invoke($action, $interaction));
        }

        // Defining commands needs the application, which only exists once the
        // gateway has said who we are. Listening above is already set up, so a
        // command defined on an earlier run still works — only new definitions
        // are lost, and the log says so.
        if ($this->bot->application === null) {
            $this->bot->getLogger()->error('[slash] no application yet, so no commands were defined');

            return;
        }

        $this->bot->application->commands->freshen()->then(
            fn (GlobalCommandRepository $repo) => $this->define($actions, $repo),
            fn (\Throwable $e) => $this->bot->getLogger()->error(
                '[slash] could not read existing commands, so none were defined: ' . $e->getMessage(),
            ),
        );
    }

    /**
     * @return array<string, Action>
     */
    private function slashActions(): array
    {
        return array_filter(
            $this->registry->forSurface(Surface::Discord),
            static fn (Action $action): bool => $action->slash !== null,
        );
    }

    /**
     * @param array<string, Action> $actions
     */
    private function define(array $actions, GlobalCommandRepository $repo): void
    {
        foreach ($actions as $action) {
            $builder = $this->build($action);

            // Upsert rather than skip-if-present. Discord treats a create with
            // an existing name as an update, and skipping would mean a changed
            // option list never reaches Discord — the command would keep the
            // shape it had the first time it was ever registered.
            $repo->save($builder->create($repo), 'TwitchBot command definition')->then(
                fn () => $this->bot->getLogger()->debug('[slash] defined /' . $action->name),
                fn (\Throwable $e) => $this->bot->getLogger()->error(
                    '[slash] could not define /' . $action->name . ': ' . $e->getMessage(),
                ),
            );
        }

        $this->bot->getLogger()->info(sprintf('[slash] %d slash command(s) defined', count($actions)));
    }

    private function build(Action $action): CommandBuilder
    {
        $spec = $action->slash;
        \assert($spec !== null);

        $builder = CommandBuilder::new()
            ->setType(Command::CHAT_INPUT)
            ->setName($action->name)
            ->setDescription($this->describe($action))
            ->addIntegrationType(Application::INTEGRATION_TYPE_GUILD_INSTALL);

        // Anything above Everyone acts on a server's configuration or a
        // broadcaster's channel, and has no meaning in a DM.
        if ($action->access !== Access::Everyone) {
            $builder->setContext([Interaction::CONTEXT_TYPE_GUILD]);
        }

        foreach ($spec->subcommands as $subcommand) {
            $option = (new DiscordOption($this->bot))
                ->setType(DiscordOption::SUB_COMMAND)
                ->setName($subcommand->name)
                ->setDescription($subcommand->description);

            foreach ($subcommand->options as $child) {
                $option->addOption($this->option($child));
            }

            $builder->addOption($option);
        }

        foreach ($spec->options as $child) {
            $builder->addOption($this->option($child));
        }

        return $builder;
    }

    private function option(SlashOption $option): DiscordOption
    {
        return (new DiscordOption($this->bot))
            ->setType($option->type)
            ->setName($option->name)
            ->setDescription($option->description)
            ->setRequired($option->required);
    }

    /** Discord caps a description at 100 characters and rejects an empty one. */
    private function describe(Action $action): string
    {
        $description = $action->description !== '' ? $action->description : 'No description provided.';

        if ($action->access !== Access::Everyone) {
            $suffix = ' (' . $action->access->label() . ' only)';

            if (mb_strlen($description . $suffix) <= 100) {
                $description .= $suffix;
            }
        }

        return mb_substr($description, 0, 100);
    }

    // ── Dispatch ───────────────────────────────────────────────────────

    private function invoke(Action $action, Interaction $interaction): void
    {
        $access = Permissions::accessForInteraction($interaction, $this->bot->getConfig()->discordOwnerId);

        if (! $access->satisfies($action->access)) {
            $this->bot->getLogger()->info(sprintf(
                '[slash] denied /%s for user %s in guild %s (needs %s, has %s, permissions=%s)',
                $action->name,
                (string) ($interaction->user->id ?? '?'),
                (string) ($interaction->guild_id ?? 'dm'),
                $action->access->label(),
                $access->label(),
                Permissions::bitsFromInteraction($interaction) ?? 'null',
            ));

            $interaction->respondWithMessage(
                $this->builder(sprintf('That command is limited to %s.', $action->access->label())),
                true,
            )->then(null, fn (\Throwable $e) => $this->logFailure($action, $e));

            return;
        }

        $spec = $action->slash;
        \assert($spec !== null);

        $ephemeral = $spec->ephemeral || $action->sensitive;
        $arguments = $this->argumentsFor($spec, $interaction);

        // Defer first. Discord discards an interaction that is not acknowledged
        // within three seconds, and these handlers make Helix calls, so nothing
        // guarantees the reply arrives inside that window.
        $interaction->acknowledgeWithResponse($ephemeral)->then(
            fn () => $this->contextFor($interaction, $access, $arguments, ! $ephemeral)->then(
                fn (Context $context) => $this->settle($action->run($context, $arguments))->then(
                    fn (?string $reply) => $this->reply($interaction, $reply ?? 'Done.'),
                    fn (\Throwable $e) => $this->fail($interaction, $action, $e),
                ),
                fn (\Throwable $e) => $this->fail($interaction, $action, $e),
            ),
            fn (\Throwable $e) => $this->logFailure($action, $e),
        );
    }

    /**
     * Turns an interaction's typed options into the arguments a prefix handler
     * expects.
     *
     * Each value is recorded twice: by name, and positionally in declaration
     * order. That is what lets one handler read `get(1)` and another
     * `named('twitch')` against the same invocation.
     *
     * Positional recording stops at the first option the user omitted. Carrying
     * on would shift every later value down an index, so a handler reading
     * `get(2)` would silently receive what was meant for `get(3)` — the kind of
     * bug that bans the wrong person.
     */
    private function argumentsFor(Slash $spec, Interaction $interaction): Arguments
    {
        $data = $interaction->data ?? null;

        if ($spec->hasSubcommands()) {
            $invoked = $data?->options?->first();
            $name = (string) ($invoked?->name ?? '');

            foreach ($spec->subcommands as $subcommand) {
                if ($subcommand->name !== $name) {
                    continue;
                }

                [$positional, $named] = $this->values($subcommand->options, $invoked?->options);

                return Arguments::fromParts([$name, ...$positional], $named);
            }

            return Arguments::fromParts($name === '' ? [] : [$name]);
        }

        [$positional, $named] = $this->values($spec->options, $data?->options);

        return Arguments::fromParts($positional, $named);
    }

    /**
     * @param list<SlashOption> $declared
     *
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private function values(array $declared, mixed $supplied): array
    {
        $positional = [];
        $named = [];
        $contiguous = true;

        foreach ($declared as $option) {
            $raw = $supplied?->get('name', $option->name)?->value ?? null;

            if ($raw === null) {
                $contiguous = false;

                continue;
            }

            $rendered = $option->render($raw);
            $named[$option->name] = $rendered;

            if ($contiguous) {
                $positional[] = $rendered;
            }
        }

        return [$positional, $named];
    }

    /**
     * Builds the invocation context, resolving which Twitch channel this
     * Discord channel acts on. Mirrors {@see DiscordAdapter::contextFor()}.
     *
     * @return PromiseInterface<Context>
     */
    private function contextFor(Interaction $interaction, Access $access, Arguments $arguments, bool $isPublic): PromiseInterface
    {
        $explicit = $arguments->named('channel');
        $login = $explicit !== null && ! str_starts_with($explicit, '<#')
            ? MessageText::normalizeLogin($explicit)
            : $this->bot->getStore()->links()->twitchFor((string) ($interaction->channel_id ?? ''));

        $base = new Context(
            $this->bot,
            Surface::Discord,
            $access,
            (string) ($interaction->user->global_name ?? $interaction->user->username ?? 'someone'),
            (string) ($interaction->user->id ?? ''),
            null,
            null,
            $isPublic,
            $interaction,
        );

        if ($login === null || $login === '') {
            return resolve($base);
        }

        return $this->bot->resolveTwitchUser($login)->then(
            static fn (?array $user) => $user === null
                ? $base->withBroadcaster(null, $login)
                : $base->withBroadcaster($user['id'], $user['login']),
        );
    }

    /** @return PromiseInterface<string|null> */
    private function settle(mixed $result): PromiseInterface
    {
        if ($result instanceof PromiseInterface) {
            return $result->then(static fn ($value) => $value === null ? null : (string) $value);
        }

        return resolve($result === null ? null : (string) $result);
    }

    private function reply(Interaction $interaction, string $text): void
    {
        $interaction->updateOriginalResponse($this->builder($text))->then(
            null,
            fn (\Throwable $e) => $this->bot->getLogger()->debug('[slash] could not reply: ' . $e->getMessage()),
        );
    }

    private function fail(Interaction $interaction, Action $action, \Throwable $e): void
    {
        if ($e instanceof ActionError) {
            $this->reply($interaction, $e->getMessage());

            return;
        }

        $this->logFailure($action, $e);
        $this->reply($interaction, 'That did not work. The details are in the bot log.');
    }

    private function logFailure(Action $action, \Throwable $e): void
    {
        // Anything that is not an ActionError may carry internals — a URL with
        // a token in the query string, a file path — so it is logged in full
        // and summarised in chat.
        $this->bot->getLogger()->error(
            sprintf('[slash] /%s failed: %s', $action->name, $e->getMessage()),
            ['exception' => $e],
        );
    }

    /** Every outbound message: clamped, and pinging nobody. */
    private function builder(string $text): MessageBuilder
    {
        return MessageBuilder::new()
            ->setContent(Format::clamp($text, Surface::Discord))
            ->setAllowedMentions(['parse' => []]);
    }
}
