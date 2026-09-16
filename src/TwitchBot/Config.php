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
 * Everything the bot needs to start, read from the environment.
 *
 * Secrets only ever come from the environment — never from the JSON store,
 * which is runtime state a server admin edits through chat and which should be
 * safe to read, back up, or paste into an issue.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Config
{
    /** What the bot answers to on Discord when no prefix is configured. */
    public const DEFAULT_DISCORD_PREFIX = '!';

    /** And on Twitch. Kept separate: the two chats often want different ones. */
    public const DEFAULT_TWITCH_PREFIX = '!';

    private function __construct(
        public readonly string $discordToken,
        public readonly string $twitchClientId,
        public readonly string $twitchClientSecret,
        public readonly string $twitchNick,
        public readonly ?string $twitchToken,
        public readonly ?string $twitchRefreshToken,
        public readonly string $discordPrefix,
        public readonly string $twitchPrefix,
        public readonly ?string $discordOwnerId,
        public readonly ?string $twitchOwnerLogin,
        public readonly string $storePath,
        public readonly string $envPath,
        public readonly string $logLevel,
    ) {
    }

    /**
     * Whether a client secret is available.
     *
     * Without one the bot still runs — Helix sends only the bearer token and
     * `Client-Id`, and IRC needs neither — but `OAuth::form()` refuses every
     * grant except device-code, so the access token cannot be *refreshed* when
     * it expires (roughly every four hours). Recovery then depends on someone
     * approving a device code. See {@see Bot::reauthorizer()}.
     */
    public function canRefreshTwitchToken(): bool
    {
        return $this->twitchClientSecret !== '';
    }

    /**
     * Whether anyone at all can run owner-gated commands.
     *
     * Both ids are optional, and when neither is set the generic API dispatcher
     * is unreachable from either chat. That is the intended default: a bot that
     * can hit any endpoint should require someone to have said who is allowed
     * to do that.
     */
    public function hasOwner(): bool
    {
        return ($this->discordOwnerId ?? '') !== '' || ($this->twitchOwnerLogin ?? '') !== '';
    }

    /**
     * Reads a `.env`-style file (if present) then the process environment,
     * which wins — so a container can override a file without editing it.
     *
     * @throws \RuntimeException when a required value is missing.
     */
    public static function fromEnvironment(string $envPath, string $storePath): self
    {
        $file = [];

        if (is_file($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $trimmed = trim($line);
                if ($trimmed === '' || $trimmed[0] === '#' || ! str_contains($trimmed, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $trimmed, 2);
                $file[trim($key)] = trim($value, " \t\"'");
            }
        }

        $get = static function (string $key) use ($file): ?string {
            $value = getenv($key);
            if (is_string($value) && $value !== '') {
                return $value;
            }

            $value = $file[$key] ?? null;

            return is_string($value) && $value !== '' ? $value : null;
        };

        $required = static function (string $key) use ($get): string {
            $value = $get($key);
            if ($value === null) {
                throw new \RuntimeException("Missing required setting {$key} — see env.example.");
            }

            return $value;
        };

        $owner = $get('TWITCH_OWNER_LOGIN');

        return new self(
            discordToken: $required('DISCORD_TOKEN'),
            twitchClientId: $required('TWITCH_CLIENT_ID'),
            // Optional: the bot runs without it, but cannot refresh a token.
            // {@see canRefreshTwitchToken()}.
            twitchClientSecret: $get('TWITCH_CLIENT_SECRET') ?? '',
            twitchNick: $required('TWITCH_NICK'),
            twitchToken: $get('TWITCH_ACCESS_TOKEN'),
            twitchRefreshToken: $get('TWITCH_REFRESH_TOKEN'),
            discordPrefix: $get('DISCORD_PREFIX') ?? self::DEFAULT_DISCORD_PREFIX,
            twitchPrefix: $get('TWITCH_PREFIX') ?? self::DEFAULT_TWITCH_PREFIX,
            discordOwnerId: $get('DISCORD_OWNER_ID'),
            twitchOwnerLogin: $owner !== null ? strtolower($owner) : null,
            storePath: $storePath,
            envPath: $envPath,
            logLevel: strtolower($get('LOG_LEVEL') ?? 'info'),
        );
    }
}
