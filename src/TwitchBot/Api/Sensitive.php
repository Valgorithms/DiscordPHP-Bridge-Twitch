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

namespace TwitchBot\Api;

/**
 * What must never be echoed into a chat channel.
 *
 * The generic dispatcher can reach every repository method, which includes
 * `streams.key` — that returns the broadcaster's live stream key, and anyone
 * holding it can broadcast to their channel. Printing one into a public Twitch
 * chat would be an unrecoverable mistake made in one keystroke, so it is
 * guarded twice over:
 *
 * 1. {@see isSecret()} marks whole methods whose *purpose* is to return a
 *    credential. Those are refused on Twitch outright and answered by DM on
 *    Discord.
 * 2. {@see redact()} runs over every rendered response regardless, masking
 *    fields whose names look like credentials. This is the one that catches
 *    the endpoint nobody thought about — including ones added to TwitchPHP
 *    after this file was last read.
 *
 * Belt and braces, deliberately: the first list is a judgement about today's
 * API surface, and it will be out of date eventually. The second is not.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Sensitive
{
    /**
     * Methods that exist in order to hand back a credential or private detail.
     *
     * Matched as `repository.method`, case-insensitively.
     *
     * @var list<string>
     */
    private const SECRET_CALLS = [
        // The big one: a live stream key.
        'streams.key',
        // Returns the authenticated user's email address when the token
        // carries `user:read:email`.
        'users.me',
        // Pre-signed report URLs — a link is as good as the data.
        'analytics.*',
        // Extension configuration and signing secrets.
        'extensions.*secret*',
        'extensions.*jwt*',
    ];

    /**
     * Field names whose values are masked in any rendered response.
     *
     * @var list<string>
     */
    private const SECRET_FIELDS = [
        'stream_key',
        'key',
        'secret',
        'token',
        'access_token',
        'refresh_token',
        'client_secret',
        'password',
        'email',
        'url',
    ];

    /** Whether `repository.method` is one whose result is a credential. */
    public static function isSecret(string $call): bool
    {
        $call = strtolower(trim($call));

        foreach (self::SECRET_CALLS as $pattern) {
            if (fnmatch(strtolower($pattern), $call, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Masks credential-looking fields anywhere in a decoded response.
     *
     * `url` is included in the field list, which is blunt, so it is qualified
     * here: only URLs carrying a query string are masked, because those are
     * the pre-signed ones where the signature *is* the credential. A plain
     * `https://twitch.tv/foo` or a profile image survives intact, since
     * blanking those would make half the API's output unreadable for no gain.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public static function redact(mixed $value): mixed
    {
        if (is_object($value)) {
            $value = (array) $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $out = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && self::isSecretField($key) && is_scalar($item)) {
                $out[$key] = self::mask((string) $key, (string) $item);

                continue;
            }

            $out[$key] = self::redact($item);
        }

        return $out;
    }

    /** Whether a field name looks like it holds a credential. */
    public static function isSecretField(string $name): bool
    {
        $name = strtolower($name);

        foreach (self::SECRET_FIELDS as $field) {
            if ($name === $field || str_ends_with($name, '_' . $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replaces a value with a marker, keeping enough to be recognisable but not
     * enough to be usable.
     */
    private static function mask(string $field, string $value): string
    {
        if ($value === '') {
            return '';
        }

        // Only signed URLs are secret; an ordinary link is just a link.
        if (str_ends_with(strtolower($field), 'url') && ! str_contains($value, '?')) {
            return $value;
        }

        return '[redacted:' . strlen($value) . ' chars]';
    }
}
