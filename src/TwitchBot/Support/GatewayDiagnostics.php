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

use TwitchBot\Bot;

/**
 * Turns a Discord gateway close code into something you can act on.
 *
 * DiscordPHP logs `not reconnecting - critical op code` and puts the code in
 * the record's *context*, which a log line that prints only the message throws
 * away. What is left says the bot stopped and nothing about why — and the two
 * most common causes have completely different fixes that look identical from
 * the outside: the bot connects, identifies, and is dropped a second later.
 *
 * Every string here names the specific thing to change, because "authentication
 * failed" sends people to re-copy a token that was never the problem.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class GatewayDiagnostics
{
    /** Codes DiscordPHP refuses to reconnect after — the ones worth explaining. */
    public const FATAL = [4004, 4010, 4011, 4012, 4013, 4014];

    /**
     * A plain-English explanation and fix, or `null` for a code with no
     * better advice than the reason Discord already gave.
     */
    public static function explain(int $op, string $reason = ''): ?string
    {
        return match ($op) {
            4004 => implode("\n", [
                'Discord rejected the token (4004).',
                '  · DISCORD_TOKEN must be the BOT token, from Developer Portal → your app → Bot → Reset Token.',
                '    It is not the Client Secret, the Application ID, or the OAuth client id — those are',
                '    different strings on nearby pages, and all of them fail exactly like this.',
                '  · Resetting a token invalidates the previous one immediately. If you reset it recently,',
                '    the value in .env is now dead even though it looks fine.',
                '  · Check for a stray quote, trailing space, or newline in .env.',
            ]),

            4014 => implode("\n", [
                'Discord refused the privileged intents this bot asks for (4014).',
                '  · Enable MESSAGE CONTENT INTENT: Developer Portal → your app → Bot → Privileged Gateway Intents.',
                '    This is almost always it on a newly created application — the toggle is off by default.',
                '  · The bot needs it to read message text, which is what the relay relays and what',
                '    prefix commands are parsed from.',
                '  · Slash commands do NOT need it. If you would rather not enable it, run without the relay:',
                '    see Bot::INTENTS and drop Intents::MESSAGE_CONTENT.',
                '  · Past 100 servers the intent needs Discord verification; below that the toggle is enough.',
            ]),

            4013 => implode("\n", [
                'Discord rejected the intent value itself (4013).',
                '  · Something is wrong with the computed bitfield rather than with permissions.',
                '    Check Bot::INTENTS.',
            ]),

            4011 => implode("\n", [
                'This bot is in too many servers for one connection (4011).',
                '  · Discord requires sharding past ~2500 guilds. DiscordPHP supports it; set the',
                '    shard options when constructing the client.',
            ]),

            4010 => 'Invalid shard configuration (4010). Check the shard id and count passed to the client.',

            4012 => 'Discord rejected the gateway version (4012). This needs a DiscordPHP update.',

            default => null,
        };
    }

    /**
     * The whole message to print for a fatal close, including the raw code and
     * Discord's own reason — which is worth keeping even when the explanation
     * above is better, since it is what a search engine will match.
     */
    public static function report(int $op, string $reason = ''): string
    {
        $explanation = self::explain($op, $reason)
            ?? sprintf('The gateway closed with code %d and will not reconnect.', $op);

        $lines = [
            '',
            '─────────────────────────────────────────────────────────────────',
            $explanation,
        ];

        if (trim($reason) !== '') {
            $lines[] = '';
            $lines[] = 'Discord said: ' . trim($reason);
        }

        $lines[] = '─────────────────────────────────────────────────────────────────';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /** Whether a close code is one the client will not recover from on its own. */
    public static function isFatal(int $op): bool
    {
        return in_array($op, self::FATAL, true);
    }

    /**
     * Pulls the close code out of a log record's context.
     *
     * DiscordPHP is the only thing that knows the code, and it only ever
     * reports it by logging — there is no event to listen for — so reading it
     * back off the record is the one hook available without patching the
     * library. Returns `null` for anything that is not a fatal gateway close,
     * which is every other record the logger will ever see.
     *
     * @param array<string, mixed> $context
     */
    public static function fromLogContext(string $message, array $context): ?int
    {
        if (! str_contains($message, 'critical op code')) {
            return null;
        }

        $op = $context['op'] ?? null;

        return is_numeric($op) && self::isFatal((int) $op) ? (int) $op : null;
    }
}
