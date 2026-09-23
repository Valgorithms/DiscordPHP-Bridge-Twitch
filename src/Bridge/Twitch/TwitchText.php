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

namespace Bridge\Twitch;

use Bridge\Message\Outgoing;
use Bridge\Support\MessageText;

/**
 * The parts of turning a message into text that are Twitch's rules rather than
 * anybody's.
 *
 * Everything shared — resolving Discord's mention markup, budgeting attachment
 * links, truncating on a character boundary — lives in
 * {@see MessageText} and is used from here. What is left is what IRC and Twitch
 * specifically demand, and it is not much, but every line of it matters:
 * a message that keeps a newline becomes an IRC command, and one that starts
 * with a slash becomes a chat command.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class TwitchText
{
    /** What Twitch accepts in one chat message. */
    public const LIMIT = 500;

    /**
     * Strips what would let a message escape its `PRIVMSG` line.
     *
     * `Irc::say()` interpolates into `PRIVMSG #chan :<text>\r\n`. A message
     * containing CR or LF would end that line, and the remainder would be
     * parsed as a fresh IRC command — letting anyone who can make the bot speak
     * issue arbitrary IRC as the bot account, including commands as a channel
     * moderator.
     *
     * Newlines become spaces rather than vanishing, so a multi-line message
     * stays readable instead of running words together. Every other C0/C1
     * control character is dropped outright.
     */
    /**
     * A name that is safe to *start* a chat line with.
     *
     * The name comes first on every relayed line, and it is chosen by whoever
     * is speaking — a Discord nickname, a Telegram name. The other bots in a
     * Twitch chat (Nightbot, StreamElements, Fossabot) read a line starting
     * with `!` as a command, and the bridge's account is usually a moderator
     * there, so a nickname like `!addcom !x` would be run with a moderator's
     * authority by somebody who is not one. Leading symbols are dropped; what
     * is left starts with a letter or a digit.
     */
    public static function speaker(string $name): string
    {
        $name = preg_replace('/^[^\p{L}\p{N}]+/u', '', self::sanitizeIrc($name)) ?? '';

        return $name === '' ? 'someone' : $name;
    }

    public static function sanitizeIrc(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\n", "\v", "\f"], ' ', $text);
        $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '';

        return MessageText::collapseWhitespace($text);
    }

    /**
     * Renders a Discord message for Twitch chat, or `null` when there is
     * nothing worth relaying.
     *
     * The `author:` prefix is not only cosmetic: it guarantees the line never
     * *begins* with `/` or `.`, which Twitch parses as a chat command. A bare
     * relayed `/ban someone` would otherwise execute if the bot holds moderator
     * on the target channel.
     *
     * Attachments are relayed as their CDN links, so someone in chat can
     * actually open the picture rather than being told one exists. They are
     * budgeted *before* the message text and appended after truncation, not
     * before it: a link that has had its tail cut off is not a link, whereas a
     * shortened sentence still reads.
     */
    public static function compose(Outgoing $message, int $limit = self::LIMIT): ?string
    {
        $body = self::sanitizeIrc(MessageText::resolveMentions(
            $message->text,
            $message->userNames,
            $message->channelNames,
            $message->roleNames,
        ));

        $prefix = self::speaker($message->author) . ': ';
        $budget = max(1, $limit - MessageText::length($prefix));
        $suffix = MessageText::attachmentLinks($message->mediaUrls(), $budget, self::sanitizeIrc(...));

        // An attachment with no caption is still worth relaying — it is the
        // whole message.
        if ($body === '' && $suffix === '') {
            return null;
        }

        $body = MessageText::truncate($body, max(0, $budget - MessageText::length($suffix)));

        return $prefix . ltrim($body . $suffix);
    }

    /**
     * Makes a line safe to hand to `Irc::say()`.
     *
     * Two separate hazards. CR and LF would terminate the `PRIVMSG` line and
     * let the remainder be parsed as a fresh IRC command — so they are stripped
     * rather than escaped. And Twitch reads a leading `/` or `.` as a chat
     * command, so a reply that happens to begin with one (an API error quoting
     * a path, say) would be executed instead of said; a leading zero-width
     * space would be invisible but is also silently dropped by some clients, so
     * the character is prefixed with a space instead.
     */
    public static function safeLine(string $text, int $limit = self::LIMIT): string
    {
        $text = self::sanitizeIrc($text);

        if ($text !== '' && ($text[0] === '/' || $text[0] === '.')) {
            $text = ' ' . $text;
        }

        return MessageText::truncate($text, $limit);
    }

    /**
     * Normalises a Twitch channel reference (`#Foo`, `https://twitch.tv/Foo`)
     * to a bare login.
     *
     * What this returns is what gets stored, so it has to be stable:
     * normalising one way today and another tomorrow orphans every bridge made
     * before the change.
     */
    public static function normalizeLogin(string $input): ?string
    {
        $login = trim($input);

        // The scheme is optional: people paste `twitch.tv/name` as often as
        // the full URL, and refusing it is a worse answer than accepting it.
        $login = preg_replace('#^(?:https?://)?(?:www\.)?twitch\.tv/#i', '', $login) ?? $login;
        $login = strtolower(ltrim(trim($login), '#@'));
        $login = explode('/', $login)[0];
        $login = explode('?', $login)[0];

        // Twitch logins: 4-25 characters, alphanumerics and underscore. Some
        // legacy accounts are shorter, so only the upper bound is strict.
        return preg_match('/^[a-z0-9_]{1,25}$/', $login) === 1 ? $login : null;
    }
}
