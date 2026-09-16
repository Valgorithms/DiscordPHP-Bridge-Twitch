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
 * Turns a message from one network into something safe to say on the other.
 *
 * Pure and side-effect free, which is the point: everything that decides what
 * text leaves this process lives here, where it can be tested directly rather
 * than inferred from a live bridge.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class MessageText
{
    /** Twitch drops anything past 500 characters. */
    public const TWITCH_LIMIT = 500;

    /** Discord rejects a message body past 2000. */
    public const DISCORD_LIMIT = 2000;

    /**
     * Strips everything that could break out of a single IRC line.
     *
     * This is the important one. `Irc::say()` interpolates its argument
     * straight into `PRIVMSG #chan :<text>\r\n`, so a relayed message
     * containing CR or LF does not merely look wrong — it ends the line and
     * the remainder is parsed as a fresh IRC command. Anyone who could type
     * in the bridged Discord channel could otherwise make the bot issue
     * arbitrary IRC, including commands as a channel moderator.
     *
     * Newlines become spaces rather than vanishing, so multi-line messages
     * stay readable instead of running words together. Every other C0/C1
     * control character is dropped outright.
     */
    public static function sanitizeIrc(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\n", "\v", "\f"], ' ', $text);
        $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '';

        return self::collapseWhitespace($text);
    }

    /**
     * Formats a Discord message for Twitch chat, or `null` when there is
     * nothing worth relaying (an embed-only or empty message).
     *
     * The `author:` prefix is not only cosmetic: it guarantees the line never
     * *begins* with `/` or `.`, which Twitch parses as a chat command. A bare
     * relayed `/ban someone` would otherwise execute if the bot holds
     * moderator on the target channel.
     *
     * Attachments are relayed as their CDN links, so someone in Twitch chat can
     * actually open the picture rather than being told one exists. They are
     * budgeted *before* the message text and appended after truncation, not
     * before it: a link that has had its tail cut off is not a link, whereas a
     * shortened sentence still reads.
     *
     * @param array<string, string> $userNames    Discord user id => display name, for resolving `<@id>`.
     * @param array<string, string> $channelNames Discord channel id => name, for `<#id>`.
     * @param array<string, string> $roleNames    Discord role id => name, for `<@&id>`.
     * @param list<string>          $attachments  Attachment URLs, in the order posted.
     */
    public static function forTwitch(
        string $content,
        string $author,
        array $userNames = [],
        array $channelNames = [],
        array $roleNames = [],
        array $attachments = [],
        int $limit = self::TWITCH_LIMIT,
    ): ?string {
        $body = self::sanitizeIrc(self::resolveMentions($content, $userNames, $channelNames, $roleNames));
        $prefix = self::sanitizeIrc($author) . ': ';

        $budget = max(1, $limit - self::length($prefix));
        $suffix = self::attachmentLinks($attachments, $budget);

        // An attachment with no caption is still worth relaying — it is the
        // whole message.
        if ($body === '' && $suffix === '') {
            return null;
        }

        $body = self::truncate($body, max(0, $budget - self::length($suffix)));

        return $prefix . ltrim($body . $suffix);
    }

    /**
     * Renders attachment URLs into the space left over, longest-lived first.
     *
     * Fits as many whole links as the budget allows and counts the rest, since
     * a truncated URL is worse than an honest "(+2 more)" — it looks clickable
     * and goes nowhere. When not even one fits, it degrades to the count, which
     * is at least what the old behaviour was.
     *
     * A caveat worth knowing rather than discovering: Discord's CDN links are
     * signed and expire roughly a day after they are issued. A link relayed
     * into Twitch chat works for viewers reading along live, and will be dead
     * by the time anyone reads the logs. Nothing here can prevent that — the
     * unsigned form of these URLs no longer exists.
     *
     * @param list<string> $urls
     */
    private static function attachmentLinks(array $urls, int $budget): string
    {
        $urls = array_values(array_filter(array_map(
            static fn (mixed $url): string => self::sanitizeIrc((string) $url),
            $urls,
        ), self::isRelayableUrl(...)));

        if ($urls === []) {
            return '';
        }

        $rendered = '';
        $shown = 0;

        foreach ($urls as $url) {
            $remaining = count($urls) - ($shown + 1);
            $tail = $remaining > 0 ? sprintf(' (+%d more)', $remaining) : '';
            $candidate = $rendered . ' ' . $url;

            if (self::length($candidate . $tail) > $budget) {
                break;
            }

            $rendered = $candidate;
            ++$shown;
        }

        if ($shown === 0) {
            $marker = sprintf(' [%d file%s]', count($urls), count($urls) === 1 ? '' : 's');

            return self::length($marker) <= $budget ? $marker : '';
        }

        $remaining = count($urls) - $shown;

        return $rendered . ($remaining > 0 ? sprintf(' (+%d more)', $remaining) : '');
    }

    /**
     * Whether a URL is safe to put in front of a public chat as a link.
     *
     * The host is deliberately not pinned to Discord's CDN. These come from the
     * gateway's own attachment objects, so they are already trusted, and
     * hard-coding hostnames would mean a future CDN domain silently degrading
     * every attachment to a bare count. What is checked is the shape: HTTPS
     * only, and nothing that could break out of the IRC line.
     */
    private static function isRelayableUrl(string $url): bool
    {
        return $url !== ''
            && str_starts_with($url, 'https://')
            && preg_match('/[\s\x00-\x1F\x7F]/u', $url) !== 1;
    }

    /**
     * Formats a Twitch chat message for Discord.
     *
     * Content is passed through as written. Nothing is escaped, because the
     * delivery side sends `allowed_mentions: none` — that neuters `@everyone`
     * at the API rather than by mangling the text, so a viewer who types an
     * `@` still reads as having typed one.
     */
    public static function forDiscord(string $content, int $limit = self::DISCORD_LIMIT): ?string
    {
        $body = self::collapseWhitespace(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $content) ?? '');

        return $body === '' ? null : self::truncate($body, $limit);
    }

    /**
     * Rewrites Discord's `<@id>` / `<#id>` / `<@&id>` / `<a:name:id>` markup
     * into something legible in a plain-text chat. Unknown ids degrade to a
     * readable placeholder rather than leaking a raw snowflake.
     *
     * @param array<string, string> $userNames
     * @param array<string, string> $channelNames
     * @param array<string, string> $roleNames
     */
    public static function resolveMentions(
        string $content,
        array $userNames = [],
        array $channelNames = [],
        array $roleNames = [],
    ): string {
        // Custom emoji <:name:id> / <a:name:id> → :name:
        $content = preg_replace('/<a?:([A-Za-z0-9_]+):\d+>/', ':$1:', $content) ?? $content;

        $content = preg_replace_callback(
            '/<@!?(\d+)>/',
            static fn (array $m): string => '@' . ($userNames[$m[1]] ?? 'someone'),
            $content,
        ) ?? $content;

        $content = preg_replace_callback(
            '/<@&(\d+)>/',
            static fn (array $m): string => '@' . ($roleNames[$m[1]] ?? 'role'),
            $content,
        ) ?? $content;

        return preg_replace_callback(
            '/<#(\d+)>/',
            static fn (array $m): string => '#' . ($channelNames[$m[1]] ?? 'channel'),
            $content,
        ) ?? $content;
    }

    /** Normalises a Twitch channel reference (`#Foo`, `https://twitch.tv/Foo`) to a bare login. */
    public static function normalizeLogin(string $input): ?string
    {
        $login = trim($input);
        $login = preg_replace('#^https?://(?:www\.)?twitch\.tv/#i', '', $login) ?? $login;
        $login = strtolower(ltrim(trim($login), '#@'));
        $login = explode('/', $login)[0];
        $login = explode('?', $login)[0];

        // Twitch logins: 4-25 chars, alphanumerics and underscore. Some legacy
        // accounts are shorter, so only the upper bound is enforced strictly.
        return preg_match('/^[a-z0-9_]{1,25}$/', $login) === 1 ? $login : null;
    }

    /** Truncates on a character boundary, marking that it happened. */
    public static function truncate(string $text, int $limit): string
    {
        if ($limit <= 0 || self::length($text) <= $limit) {
            return $text;
        }

        $ellipsis = '…';
        $keep = max(0, $limit - 1);

        return (function_exists('mb_substr') ? mb_substr($text, 0, $keep, 'UTF-8') : substr($text, 0, $keep)) . $ellipsis;
    }

    private static function collapseWhitespace(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }
}
