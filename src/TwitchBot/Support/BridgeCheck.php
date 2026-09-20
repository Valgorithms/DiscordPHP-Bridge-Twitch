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

namespace TwitchBot\Support;

/**
 * Turns the result of the startup check into something a human can act on.
 *
 * A bridge is a pair of ends that outlive the process, and either can stop
 * working while the bot is down: the Discord channel can be deleted, the bot
 * can be removed from the server, or the Twitch channel can be renamed or
 * banned. None of that produces an error at startup — it produces a bridge
 * that quietly relays nothing, which is indistinguishable from "nobody has
 * said anything".
 *
 * So each restored bridge is probed once, and anything broken is named. Pure,
 * so the wording and the counting can be tested without a socket.
 *
 * Nothing is pruned automatically. A guild can be briefly unavailable during a
 * Discord outage, and deleting somebody's configuration because of a bad ten
 * seconds is far worse than logging a line they can act on.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class BridgeCheck
{
    /**
     * One line per broken bridge, plus how many are healthy.
     *
     * @param list<array{channel_id: string, login: string, channel_ok: bool, twitch_ok: bool, joined: bool}> $rows
     *
     * @return array{healthy: int, problems: list<string>}
     */
    public static function summarise(array $rows): array
    {
        $healthy = 0;
        $problems = [];

        foreach ($rows as $row) {
            $problem = self::describe($row);

            if ($problem === null) {
                $healthy++;

                continue;
            }

            $problems[] = $problem;
        }

        return ['healthy' => $healthy, 'problems' => $problems];
    }

    /**
     * What is wrong with one bridge, or `null` when it is fine.
     *
     * @param array{channel_id: string, login: string, channel_ok: bool, twitch_ok: bool, joined: bool} $row
     */
    public static function describe(array $row): ?string
    {
        if (! $row['channel_ok'] && ! $row['twitch_ok']) {
            return sprintf(
                'channel %s ⇄ %s: neither end is reachable — the Discord channel is gone or I was removed from the server, and there is no such Twitch channel any more.',
                $row['channel_id'],
                $row['login'],
            );
        }

        if (! $row['channel_ok']) {
            return sprintf(
                'channel %s ⇄ %s: I can\'t see that Discord channel any more. Anything said in Twitch chat has nowhere to go.',
                $row['channel_id'],
                $row['login'],
            );
        }

        if (! $row['twitch_ok']) {
            return sprintf(
                'channel %s ⇄ %s: there is no Twitch channel by that name any more — it was renamed, or the account is gone.',
                $row['channel_id'],
                $row['login'],
            );
        }

        // The IRC membership is what actually carries chat, and it is the one
        // thing here that a restart is supposed to re-establish by itself.
        if (! $row['joined']) {
            return sprintf(
                'channel %s ⇄ %s: the Twitch channel exists but I am not in its chat, so nothing will arrive from it.',
                $row['channel_id'],
                $row['login'],
            );
        }

        return null;
    }

    /**
     * The startup line for a working bridge set: what was restored, and from
     * where.
     */
    public static function restored(int $bridges, int $guilds, string $path): string
    {
        if ($bridges === 0) {
            return sprintf('no bridges configured yet (%s)', $path);
        }

        return sprintf(
            'restored %d bridge%s across %d server%s from %s',
            $bridges,
            $bridges === 1 ? '' : 's',
            $guilds,
            $guilds === 1 ? '' : 's',
            $path,
        );
    }
}
