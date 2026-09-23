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

use Bridge\Bot;
use Bridge\Command\Access;
use Bridge\Config;
use Bridge\Environment;
use Bridge\Room;
use Bridge\Store;
use Bridge\Support\Filesystem;
use Bridge\Twitch\TwitchAdapter;
use Bridge\Twitch\TwitchConfig;
use Bridge\Twitch\TwitchConnector;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use React\EventLoop\StreamSelectLoop;
use Twitch\Parts\ChatMessage;
use Twitch\Twitch;

/**
 * Twitch chat's half of the command catalogue: reading rank off a message and
 * handing the rest to the core's dispatcher.
 *
 * The bot is real and never connects; its loop is never run.
 */
final class TwitchAdapterTest extends TestCase
{
    private string $dir;

    private TwitchConnector $connector;

    private TwitchAdapter $adapter;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bridge-twitch-' . bin2hex(random_bytes(6));
        @mkdir($this->dir, 0o777, true);

        $path = $this->dir . '/bridges.json';
        $bot = new Bot(
            Config::fromEnvironment(Environment::fromArray(['DISCORD_TOKEN' => 'test.token.here']), $path),
            new Store($path, Filesystem::blocking()),
            ['logger' => new NullLogger(), 'loop' => new StreamSelectLoop()],
        );

        $this->connector = new TwitchConnector(TwitchConfig::fromEnvironment(Environment::fromArray([
            'TWITCH_CLIENT_ID' => 'client',
            'TWITCH_NICK' => 'bridgebot',
            'TWITCH_OWNER_LOGIN' => 'Valithor',
        ], $this->dir . '/.env')));

        $bot->addConnector($this->connector);
        $this->adapter = new TwitchAdapter($this->connector, $bot);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        @rmdir($this->dir);
    }

    public function testRankIsReadOffTheBadges(): void
    {
        $this->assertSame(Access::Everyone, $this->adapter->invocation($this->message())->access);
        $this->assertSame(Access::Moderator, $this->adapter->invocation($this->message(['is_mod' => true]))->access);
        $this->assertSame(Access::Administrator, $this->adapter->invocation($this->message(['is_broadcaster' => true]))->access);
    }

    public function testTheConfiguredOwnerIsTheOperatorWhateverTheirBadges(): void
    {
        $who = $this->adapter->invocation($this->message(['user' => 'valithor']));

        $this->assertSame(Access::Operator, $who->access);
    }

    public function testTheRoomIsTheLoginAndItsIdComesFromTheTag(): void
    {
        // The id is what Helix wants, and it is already on the message.
        $who = $this->adapter->invocation($this->message([
            'channel' => 'CoffeesCrafts',
            'tags' => ['room-id' => '123456'],
        ]));

        $this->assertSame('coffeescrafts', $who->room);
        $this->assertSame('123456', $who->roomId);
    }

    public function testAMessageWithoutARoomIdLeavesItToBeLookedUp(): void
    {
        $this->assertNull($this->adapter->invocation($this->message())->roomId);
    }

    public function testOnlyRegisteredCommandsAreTaken(): void
    {
        $this->assertTrue($this->adapter->handle($this->message(['content' => '!twitch'])));
        $this->assertTrue($this->adapter->handle($this->message(['content' => '!twitch uptime'])));
        $this->assertTrue($this->adapter->handle($this->message(['content' => '!bridge about'])));

        $this->assertFalse($this->adapter->handle($this->message(['content' => 'hello !twitch'])));
        $this->assertFalse($this->adapter->handle($this->message(['content' => '!drop'])));
        $this->assertFalse($this->adapter->handle($this->message(['content' => '!uptime'])));
    }

    public function testTheSurfaceUsesTheConfiguredPrefix(): void
    {
        $this->assertSame('!', $this->connector->surface()->prefix);
    }

    public function testARoomIsKeyedByLoginAndActedOnById(): void
    {
        // What the store has always held, and what Helix wants.
        $room = new Room('coffeescrafts', 'CoffeesCrafts', platformId: '123456');

        $this->assertSame('coffeescrafts', $room->id);
        $this->assertSame('123456', $room->apiId());
    }

    /** @param array<string, mixed> $attributes */
    private function message(array $attributes = []): ChatMessage
    {
        /** @var Twitch $twitch */
        $twitch = (new \ReflectionClass(Twitch::class))->newInstanceWithoutConstructor();

        return new ChatMessage($twitch, $attributes + [
            'id' => 'msg-1',
            'channel' => 'coffeescrafts',
            'user' => 'somebody',
            'user_id' => '42',
            'display_name' => 'Somebody',
            'content' => 'hello',
            'is_mod' => false,
            'is_broadcaster' => false,
            'tags' => [],
        ]);
    }
}
