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

/**
 * The live-stream side of the API: whether it is up, for how long, to how many,
 * and the things worth capturing while it is.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class StreamActions implements ProvidesActions
{
    use UsesTwitch;

    public function actions(): array
    {
        return [
            new Action(
                'twitch',
                'uptime',
                $this->uptime(...),
                'How long the stream has been live',
                cooldown: 10,
                group: 'stream',
                slash: new Slash(),
            ),
            new Action(
                'twitch',
                'viewers',
                $this->viewers(...),
                'Current viewer count',
                cooldown: 10,
                group: 'stream',
                slash: new Slash(),
            ),
            new Action(
                'twitch',
                // Not `stream`: it sits in the `stream` group, and
                // `/twitch stream stream` says nothing the group has not.
                'live',
                $this->stream(...),
                'Everything about the current stream',
                aliases: ['stream'],
                cooldown: 10,
                group: 'stream',
                slash: new Slash(),
            ),
            new Action(
                'twitch',
                'followers',
                $this->followers(...),
                'How many followers the channel has',
                cooldown: 30,
                group: 'stream',
                slash: new Slash(),
            ),
            new Action(
                'twitch',
                'clip',
                $this->clip(...),
                'Clip the last 30 seconds',
                access: Access::Moderator,
                cooldown: 30,
                group: 'stream',
                slash: new Slash(),
            ),
            new Action(
                'twitch',
                'marker',
                $this->marker(...),
                'Drop a marker in the VOD',
                '[note]',
                access: Access::Moderator,
                cooldown: 10,
                group: 'stream',
                slash: new Slash([new SlashOption('note', 'An optional note to attach to the marker.')]),
            ),
            new Action(
                'twitch',
                'search',
                $this->search(...),
                'Find a Twitch category by name',
                '<name>',
                cooldown: 10,
                // Ungrouped: looking a category up is not about the stream, and
                // a top-level `/twitch search` is where somebody would look.
                slash: new Slash([new SlashOption('name', 'The category to search for.', SlashOption::STRING, true)]),
            ),
        ];
    }

    /** @return PromiseInterface<string> */
    private function uptime(Context $context, Arguments $arguments): PromiseInterface
    {
        return $this->live($context)->then(
            function (?object $stream) use ($context): string {
                if ($stream === null) {
                    return sprintf('%s is offline.', $context->target ?? 'the channel');
                }

                $seconds = max(0, time() - $stream->started_at->getTimestamp());

                return sprintf('live for %s.', Format::duration($seconds));
            },
        );
    }

    /** @return PromiseInterface<string> */
    private function viewers(Context $context, Arguments $arguments): PromiseInterface
    {
        return $this->live($context)->then(
            function (?object $stream) use ($context): string {
                if ($stream === null) {
                    return sprintf('%s is offline.', $context->target ?? 'the channel');
                }

                $count = (int) $stream->viewer_count;

                return sprintf('%s viewer%s.', Format::number($count), $count === 1 ? '' : 's');
            },
        );
    }

    /** @return PromiseInterface<string> */
    private function stream(Context $context, Arguments $arguments): PromiseInterface
    {
        return $this->live($context)->then(
            function (?object $stream) use ($context): string {
                if ($stream === null) {
                    return sprintf('%s is offline.', $context->target ?? 'the channel');
                }

                return Format::fields($context->surface, [
                    'title' => (string) $stream->title,
                    'category' => (string) $stream->game_name,
                    'viewers' => Format::number((int) $stream->viewer_count),
                    'uptime' => Format::duration(max(0, time() - $stream->started_at->getTimestamp())),
                    'language' => (string) $stream->language,
                    'mature' => (bool) $stream->is_mature,
                ], (string) $stream->user_name . ' is live');
            },
        );
    }

    /** @return PromiseInterface<string> */
    private function followers(Context $context, Arguments $arguments): PromiseInterface
    {
        $id = $context->requireTarget();

        return $this->twitch($context)->getTwitch()->channels->followers($id, null, 1)->then(
            static fn (array $body): string => sprintf(
                '%s follower%s.',
                Format::number((int) ($body['total'] ?? 0)),
                ((int) ($body['total'] ?? 0)) === 1 ? '' : 's',
            ),
            static fn (\Throwable $e) => throw new ActionError(
                'could not read the follower count — this one needs the `moderator:read:followers` scope on a token that moderates the channel.',
            ),
        );
    }

    /** @return PromiseInterface<string> */
    private function clip(Context $context, Arguments $arguments): PromiseInterface
    {
        $id = $context->requireTarget();

        return $this->twitch($context)->getTwitch()->clips->create($id)->then(
            static function ($clip): string {
                $clipId = is_object($clip) ? (string) ($clip->id ?? '') : (string) (($clip['id'] ?? '') ?: '');

                if ($clipId === '') {
                    return 'clip requested, but Twitch did not return an id for it.';
                }

                // Twitch needs a few seconds to actually cut the clip, so the
                // URL can 404 briefly after this returns. Say so rather than
                // letting it look broken.
                return sprintf('clip created: https://clips.twitch.tv/%s (it may take a moment to appear)', $clipId);
            },
            static fn (\Throwable $e) => throw new ActionError(
                'could not clip that — the channel must be live, and the token needs the `clips:edit` scope.',
            ),
        );
    }

    /** @return PromiseInterface<string> */
    private function marker(Context $context, Arguments $arguments): PromiseInterface
    {
        $id = $context->requireTarget();
        $note = $arguments->rest();

        return $this->twitch($context)->getTwitch()->streams->createMarker($id, $note !== '' ? $note : null)->then(
            static fn (): string => $note === '' ? 'marker dropped.' : 'marker dropped: ' . $note,
            static fn (\Throwable $e) => throw new ActionError(
                'could not drop a marker — the channel must be live, and the token needs `channel:manage:broadcast`.',
            ),
        );
    }

    /** @return PromiseInterface<string> */
    private function search(Context $context, Arguments $arguments): PromiseInterface
    {
        $query = $arguments->rest();

        if ($query === '') {
            throw new ActionError('search for what? `search <category name>`');
        }

        return $this->twitch($context)->getTwitch()->search->categories($query, 5)->then(
            static function ($categories) use ($context, $query): string {
                $names = [];

                foreach ($categories as $category) {
                    $names[] = (string) $category->name;
                }

                return Format::listing(
                    $context->surface,
                    $names,
                    'categories matching "' . $query . '"',
                    'nothing matched.',
                );
            },
        );
    }

    /**
     * The current stream, or `null` when the channel is offline.
     *
     * Helix signals "offline" by returning an empty list rather than an error,
     * which is why this returns null instead of rejecting.
     *
     * @return PromiseInterface<object|null>
     */
    private function live(Context $context): PromiseInterface
    {
        $id = $context->requireTarget();

        return $this->twitch($context)->getTwitch()->streams->live(['user_id' => $id])->then(
            static fn ($streams) => $streams->first(),
        );
    }
}
