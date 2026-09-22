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

use Bridge\Environment;

/**
 * What the Twitch connector needs to start, read from the same environment the
 * bot itself was.
 *
 * Kept here rather than in the core's {@see \Bridge\Config}, which is the point
 * of the connector model: installing a network should not mean the core grows
 * fields for settings it will never read.
 *
 * Secrets only ever come from the environment — never from the JSON store,
 * which is runtime state a server admin edits through chat and which should be
 * safe to read, back up, or paste into an issue.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class TwitchConfig
{
    /** What the bot answers to in Twitch chat when no prefix is configured. */
    public const DEFAULT_PREFIX = '!';

    private function __construct(
        public readonly string $clientId,
        public readonly string $clientSecret,
        public readonly string $nick,
        public readonly ?string $token,
        public readonly ?string $refreshToken,
        public readonly string $prefix,
        public readonly ?string $ownerLogin,
        public readonly string $envPath,
    ) {
    }

    /**
     * @throws \RuntimeException when a required value is missing.
     */
    public static function fromEnvironment(Environment $environment): self
    {
        $owner = $environment->get('TWITCH_OWNER_LOGIN');

        return new self(
            clientId: $environment->require('TWITCH_CLIENT_ID'),
            // Optional: the bot runs without it, but cannot refresh a token.
            // {@see canRefreshToken()}.
            clientSecret: $environment->get('TWITCH_CLIENT_SECRET') ?? '',
            nick: $environment->require('TWITCH_NICK'),
            token: $environment->get('TWITCH_ACCESS_TOKEN'),
            refreshToken: $environment->get('TWITCH_REFRESH_TOKEN'),
            prefix: $environment->or('TWITCH_PREFIX', self::DEFAULT_PREFIX),
            ownerLogin: $owner !== null ? strtolower($owner) : null,
            envPath: $environment->path,
        );
    }

    /** Whether the environment carries enough to install this connector at all. */
    public static function isConfigured(Environment $environment): bool
    {
        return $environment->has('TWITCH_CLIENT_ID', 'TWITCH_NICK');
    }

    /**
     * Whether a client secret is available.
     *
     * Without one the bot still runs — Helix sends only the bearer token and
     * `Client-Id`, and IRC needs neither — but `OAuth::form()` refuses every
     * grant except device-code, so the access token cannot be *refreshed* when
     * it expires, roughly every four hours. Recovery then depends on somebody
     * approving a device code; see {@see TwitchConnector::reauthorizer()}.
     */
    public function canRefreshToken(): bool
    {
        return $this->clientSecret !== '';
    }

    /**
     * Whether anyone can run the operator-gated Twitch commands from Twitch
     * chat.
     *
     * Unset is the intended default: `api` can reach endpoints that ban users,
     * end streams and rewrite a channel, so it should require somebody to have
     * said who is allowed to do that.
     */
    public function hasOwner(): bool
    {
        return ($this->ownerLogin ?? '') !== '';
    }
}
