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

namespace TwitchBot;

/**
 * The routing table: which Discord channel is bridged to which Twitch channel.
 *
 * Immutable and derived from whatever {@see Store} has persisted, so routing
 * can be reasoned about — and tested — without a gateway, a socket, or a
 * config file.
 *
 * The two directions are deliberately asymmetric. A Discord channel bridges to
 * exactly one Twitch channel, so `Discord → Twitch` is a lookup. But any number
 * of servers may follow the *same* streamer, so `Twitch → Discord` fans out to
 * a list; a message in one Twitch channel can land in many Discord channels
 * across unrelated guilds.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Links
{
    /** @var array<string, string> Discord channel id => Twitch login. */
    private array $toTwitch = [];

    /** @var array<string, list<string>> Twitch login => Discord channel ids. */
    private array $toDiscord = [];

    /**
     * @param array<string, array<string, string>> $guilds guild id => [discord channel id => twitch login]
     */
    public function __construct(private readonly array $guilds = [])
    {
        foreach ($this->guilds as $channels) {
            foreach ($channels as $channelId => $login) {
                $channelId = (string) $channelId;
                $login = (string) $login;

                $this->toTwitch[$channelId] = $login;
                $this->toDiscord[$login][] = $channelId;
            }
        }
    }

    /** The Twitch channel a Discord channel relays to, or `null` when unbridged. */
    public function twitchFor(int|string $discordChannelId): ?string
    {
        return $this->toTwitch[(string) $discordChannelId] ?? null;
    }

    /**
     * Every Discord channel that should receive a message from a Twitch
     * channel — possibly across several guilds.
     *
     * @return list<string>
     */
    public function discordFor(string $twitchLogin): array
    {
        return $this->toDiscord[strtolower($twitchLogin)] ?? [];
    }

    /**
     * Every Twitch channel that needs to be joined, deduplicated: two guilds
     * following the same streamer share one IRC membership.
     *
     * @return list<string>
     */
    public function logins(): array
    {
        $logins = array_keys($this->toDiscord);
        sort($logins);

        return array_values($logins);
    }

    /**
     * One guild's links.
     *
     * @return array<string, string> discord channel id => twitch login
     */
    public function forGuild(int|string $guildId): array
    {
        return $this->guilds[(string) $guildId] ?? [];
    }

    /**
     * Every guild that has configured at least one bridge.
     *
     * @return list<string>
     */
    public function guilds(): array
    {
        return array_map(strval(...), array_keys($this->guilds));
    }

    public function isEmpty(): bool
    {
        return $this->toTwitch === [];
    }

    public function count(): int
    {
        return count($this->toTwitch);
    }

    /**
     * What to JOIN and what to PART to get from this routing table to `$next`.
     *
     * Returned as a diff rather than "just rejoin everything" so a config
     * change in one guild does not blink every other guild's bridge offline.
     *
     * @return array{join: list<string>, part: list<string>}
     */
    public function diff(self $next): array
    {
        $have = $this->logins();
        $want = $next->logins();

        return [
            'join' => array_values(array_diff($want, $have)),
            'part' => array_values(array_diff($have, $want)),
        ];
    }
}
