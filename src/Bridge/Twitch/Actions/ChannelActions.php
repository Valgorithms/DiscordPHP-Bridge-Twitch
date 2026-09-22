<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-Bridge-Twitch project.
 *
 * Copyright (c) 2026-present Valithor Obsidion <valithor@valgorithms.com>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Bridge\Twitch\Actions;

use Bridge\Capability\ProvidesActions;
use Bridge\Command\Access;
use Bridge\Command\Action;
use Bridge\Command\ActionError;
use Bridge\Command\Arguments;
use Bridge\Command\Context;
use Bridge\Command\Slash;
use Bridge\Command\SlashOption;
use Bridge\Support\Format;
use React\Promise\PromiseInterface;
use Twitch\Exceptions\MissingScopeException;

/**
 * Reading and editing the channel: title, category, tags, and the rest of what
 * `PATCH /channels` accepts.
 *
 * Each of these reads with no argument and writes with one, which is the shape
 * chat commands have had since long before Helix and is worth matching:
 * `!title` asks, `!title Back in ten` tells.
 *
 * All writes go through the bot's *own* token, so the bot account must hold
 * `channel:manage:broadcast` on the channel being edited — it is the
 * broadcaster, or an editor on it. When it is not, Twitch's refusal is
 * translated in {@see explain()} rather than surfaced raw, because the raw one
 * ("401 Unauthorized") sends people looking in entirely the wrong place.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class ChannelActions implements ProvidesActions
{
    use UsesTwitch;

    /** Twitch truncates a title past 140 characters. */
    private const TITLE_LIMIT = 140;

    public function actions(): array
    {
        return [
            new Action(
                'twitch',
                'title',
                $this->title(...),
                'Show the stream title, or set it',
                '[new title]',
                access: Access::Everyone,
                cooldown: 5,
                group: 'channel',
                slash: new Slash([new SlashOption('text', 'The new title. Leave empty to read the current one.')]),
            ),
            new Action(
                'twitch',
                'game',
                $this->game(...),
                'Show the category, or set it',
                '[category]',
                access: Access::Everyone,
                aliases: ['category'],
                cooldown: 5,
                group: 'channel',
                slash: new Slash([new SlashOption('name', 'The new category. Leave empty to read the current one.')]),
            ),
            new Action(
                'twitch',
                'tags',
                $this->tags(...),
                'Show the channel tags, or set them',
                '[tag, tag, ...]',
                access: Access::Everyone,
                cooldown: 5,
                group: 'channel',
                slash: new Slash([new SlashOption('tags', 'Comma-separated. Leave empty to read the current ones.')]),
            ),
            new Action(
                'twitch',
                // Not `channel`: it sits in the `channel` group, and
                // `/twitch channel channel` says nothing the group has not.
                'info',
                $this->channel(...),
                'Everything the channel is currently set to',
                access: Access::Everyone,
                aliases: ['channel'],
                cooldown: 10,
                group: 'channel',
                slash: new Slash(),
            ),
        ];
    }

    /** @return PromiseInterface<string> */
    private function title(Context $context, Arguments $arguments): PromiseInterface
    {
        $wanted = $arguments->rest();

        if ($wanted === '') {
            return $this->current($context)->then(
                static fn (object $channel): string => 'title: ' . ((string) $channel->title ?: '(not set)'),
            );
        }

        $this->requireWrite($context);

        if (mb_strlen($wanted) > self::TITLE_LIMIT) {
            throw new ActionError(sprintf(
                'that title is %d characters; Twitch allows %d.',
                mb_strlen($wanted),
                self::TITLE_LIMIT,
            ));
        }

        return $this->modify($context, ['title' => $wanted])
            ->then(static fn (): string => 'title set to: ' . $wanted);
    }

    /** @return PromiseInterface<string> */
    private function game(Context $context, Arguments $arguments): PromiseInterface
    {
        $wanted = $arguments->rest();

        if ($wanted === '') {
            return $this->current($context)->then(
                static fn (object $channel): string => 'category: ' . ((string) $channel->game_name ?: '(not set)'),
            );
        }

        $this->requireWrite($context);

        // Helix takes a game_id, not a name, so the name has to be resolved
        // first — and a near miss should say so rather than silently setting
        // nothing.
        return $this->twitch($context)->getTwitch()->games->fetchByName($wanted)->then(
            function ($game) use ($context, $wanted): PromiseInterface {
                if ($game === null) {
                    throw new ActionError(sprintf(
                        'Twitch has no category called "%s". Names have to match exactly — try `search %s`.',
                        $wanted,
                        $wanted,
                    ));
                }

                return $this->modify($context, ['game_id' => (string) $game->id])
                    ->then(static fn (): string => 'category set to: ' . (string) $game->name);
            },
        );
    }

    /** @return PromiseInterface<string> */
    private function tags(Context $context, Arguments $arguments): PromiseInterface
    {
        $wanted = $arguments->rest();

        if ($wanted === '') {
            return $this->current($context)->then(
                static fn (object $channel): string => Format::listing(
                    $context->surface,
                    array_map('strval', (array) ($channel->tags ?? [])),
                    'tags',
                    'none set',
                ),
            );
        }

        $this->requireWrite($context);

        $tags = array_values(array_filter(
            array_map('trim', preg_split('/\s*,\s*/', $wanted) ?: []),
            static fn (string $tag): bool => $tag !== '',
        ));

        if (count($tags) > 10) {
            throw new ActionError('Twitch allows at most 10 tags.');
        }

        return $this->modify($context, ['tags' => $tags])->then(
            static fn (): string => $tags === []
                ? 'tags cleared.'
                : 'tags set to: ' . implode(', ', $tags),
        );
    }

    /** @return PromiseInterface<string> */
    private function channel(Context $context, Arguments $arguments): PromiseInterface
    {
        return $this->current($context)->then(
            static fn (object $channel): string => Format::fields($context->surface, [
                'channel' => (string) $channel->broadcaster_name,
                'title' => (string) $channel->title,
                'category' => (string) $channel->game_name,
                'language' => (string) $channel->broadcaster_language,
                'tags' => implode(', ', array_map('strval', (array) ($channel->tags ?? []))),
                'delay' => ((int) $channel->delay) > 0 ? $channel->delay . 's' : null,
            ], 'https://twitch.tv/' . (string) $channel->broadcaster_login),
        );
    }

    // ── Shared ─────────────────────────────────────────────────────────

    /**
     * The channel this action acts on.
     *
     * @return PromiseInterface<object>
     */
    private function current(Context $context): PromiseInterface
    {
        $id = $context->requireTarget();

        return $this->twitch($context)->getTwitch()->channels->getMany([$id])->then(
            static function ($channels) use ($context): object {
                $channel = $channels->first();

                if ($channel === null) {
                    throw new ActionError(sprintf(
                        'Twitch returned nothing for %s.',
                        $context->target ?? 'that channel',
                    ));
                }

                return $channel;
            },
        );
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return PromiseInterface<null>
     */
    private function modify(Context $context, array $fields): PromiseInterface
    {
        return $this->twitch($context)->getTwitch()->channels
            ->modify($context->requireTarget(), $fields)
            ->then(null, fn (\Throwable $e) => throw $this->explain($e, $context));
    }

    /**
     * Editing a channel is gated to the broadcaster, on both platforms, which
     * on Discord means the server owner or an Administrator.
     */
    private function requireWrite(Context $context): void
    {
        if (! $context->access->satisfies(Access::Administrator)) {
            throw new ActionError('only the broadcaster can change that.');
        }
    }

    /**
     * Turns Twitch's refusal into something actionable.
     *
     * A 401 here almost never means the token is bad — the same token just read
     * the channel successfully. It means the bot account is not the broadcaster
     * and is not an editor on that channel, which is a completely different fix
     * from the one "unauthorized" suggests.
     */
    private function explain(\Throwable $e, Context $context): \Throwable
    {
        $login = $context->target ?? 'that channel';

        if ($e instanceof MissingScopeException) {
            return new ActionError(sprintf(
                'the bot token is missing the %s scope. Re-authorize it with that scope included.',
                implode(' or ', $e->scopes) !== '' ? implode(' or ', $e->scopes) : 'required',
            ));
        }

        $message = $e->getMessage();

        if (str_contains($message, '401') || stripos($message, 'unauthorized') !== false) {
            return new ActionError(sprintf(
                'Twitch refused that. The bot speaks as its own account, so it can only edit %s if it *is* that account or a channel editor on it.',
                $login,
            ));
        }

        return $e;
    }
}
