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

namespace Bridge\Twitch\Tests;

use Bridge\Environment;
use Bridge\Twitch\TwitchConfig;
use PHPUnit\Framework\TestCase;

/**
 * Only what Twitch itself needs. Reading a `.env` file is the core's job, and
 * is tested there.
 */
final class TwitchConfigTest extends TestCase
{
    public function testReadsTheTwitchSettings(): void
    {
        $config = $this->config([
            'TWITCH_CLIENT_ID' => 'cid',
            'TWITCH_CLIENT_SECRET' => 'secret',
            'TWITCH_NICK' => 'MyBot',
            'TWITCH_ACCESS_TOKEN' => 'tok',
            'TWITCH_REFRESH_TOKEN' => 'ref',
        ]);

        $this->assertSame('cid', $config->clientId);
        $this->assertSame('secret', $config->clientSecret);
        $this->assertSame('MyBot', $config->nick);
        $this->assertSame('tok', $config->token);
        $this->assertSame('ref', $config->refreshToken);
    }

    public function testAMissingRequiredSettingSaysWhichOne(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/TWITCH_NICK/');

        $this->config(['TWITCH_CLIENT_ID' => 'cid']);
    }

    public function testTheClientSecretIsOptional(): void
    {
        // The bot runs without one: Helix sends only the bearer token and
        // Client-Id, and IRC needs neither.
        $config = $this->config(['TWITCH_CLIENT_ID' => 'cid', 'TWITCH_NICK' => 'bot']);

        $this->assertSame('', $config->clientSecret);
        $this->assertFalse($config->canRefreshToken());
    }

    public function testAClientSecretIsWhatMakesRefreshingPossible(): void
    {
        // Without it, OAuth::form() refuses every grant but device-code, so the
        // token cannot be renewed when it expires in about four hours.
        $config = $this->config(['TWITCH_CLIENT_ID' => 'cid', 'TWITCH_NICK' => 'bot', 'TWITCH_CLIENT_SECRET' => 's']);

        $this->assertTrue($config->canRefreshToken());
    }

    public function testThePrefixDefaultsToBang(): void
    {
        $this->assertSame('!', $this->config($this->minimum())->prefix);
        $this->assertSame('?', $this->config($this->minimum(['TWITCH_PREFIX' => '?']))->prefix);
    }

    public function testThereIsNoOwnerUnlessOneIsNamed(): void
    {
        // The safe default: `api` can reach any endpoint, so it should be
        // unreachable until somebody says who may use it.
        $this->assertNull($this->config($this->minimum())->ownerLogin);
        $this->assertFalse($this->config($this->minimum())->hasOwner());
    }

    public function testTheOwnerLoginIsLowercasedBecauseTwitchLoginsAre(): void
    {
        $config = $this->config($this->minimum(['TWITCH_OWNER_LOGIN' => 'ValithoR']));

        $this->assertSame('valithor', $config->ownerLogin);
        $this->assertTrue($config->hasOwner());
    }

    public function testWhetherTheConnectorShouldBeInstalledAtAll(): void
    {
        // The app asks this before constructing anything, so a bot with only
        // Telegram credentials does not try to start a Twitch client.
        $this->assertTrue(TwitchConfig::isConfigured(Environment::fromArray($this->minimum())));
        $this->assertFalse(TwitchConfig::isConfigured(Environment::fromArray(['TWITCH_CLIENT_ID' => 'cid'])));
        $this->assertFalse(TwitchConfig::isConfigured(Environment::fromArray([])));
    }

    /** @param array<string, string> $extra */
    private function minimum(array $extra = []): array
    {
        return ['TWITCH_CLIENT_ID' => 'cid', 'TWITCH_NICK' => 'bot'] + $extra;
    }

    /** @param array<string, string> $values */
    private function config(array $values): TwitchConfig
    {
        return TwitchConfig::fromEnvironment(Environment::fromArray($values));
    }
}
