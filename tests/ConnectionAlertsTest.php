<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-Bridge project.
 *
 * Copyright (c) 2026-present Valithor Obsidion <valithor@discordphp.org>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Bridge\Twitch\Tests;

use Bridge\Bot;
use Bridge\Config;
use Bridge\Environment;
use Bridge\Store;
use Bridge\Support\ConnectionAlerts;
use Bridge\Support\Filesystem;
use Bridge\Twitch\TwitchConfig;
use Bridge\Twitch\TwitchConnector;
use Discord\Builders\MessageBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;
use Twitch\Chat\Irc;
use Twitch\Twitch;

use function React\Promise\resolve;

/**
 * Twitch chat that stays down reaches the owner as a DM with a button, and
 * chat coming back changes that DM. The retrying itself is TwitchPHP's.
 */
final class ConnectionAlertsTest extends TestCase
{
    private string $dir;

    private Bot $bot;

    private Twitch $twitch;

    private TwitchConnector $connector;

    /** @var list<array{sent: string, edits: list<string>}> */
    private array $dms = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bridge-twitch-' . bin2hex(random_bytes(6));
        @mkdir($this->dir, 0o777, true);

        $path = $this->dir . '/bridges.json';
        $this->bot = new Bot(
            Config::fromEnvironment(Environment::fromArray(['DISCORD_TOKEN' => 'test.token.here', 'DISCORD_OWNER_ID' => '1000']), $path),
            new Store($path, Filesystem::blocking()),
            // Nothing here resolves a name; see the core's BuildsBot.
            ['logger' => new NullLogger(), 'loop' => new StreamSelectLoop(), 'dnsConfig' => '8.8.8.8', 'socket_options' => ['dns' => '8.8.8.8']],
        );

        $this->connector = new TwitchConnector(TwitchConfig::fromEnvironment(
            Environment::fromArray(['TWITCH_CLIENT_ID' => 'cid', 'TWITCH_NICK' => 'bot']),
        ));
        $this->twitch = new Twitch(['client_id' => 'cid', 'loop' => $this->bot->getLoop()]);
        (new \ReflectionProperty(TwitchConnector::class, 'twitch'))->setValue($this->connector, $this->twitch);
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

    public function testChatThatStaysDownIsDmedAndItsReturnEditsTheDm(): void
    {
        $this->watch();

        $this->twitch->emit('chat.disconnected', [1006, 'gone', $this->twitch]);
        $this->twitch->emit('chat.reconnect_failed', [10, 'Connection to tcp://irc-ws.chat.twitch.tv:443 failed', $this->twitch]);
        $this->twitch->emit('chat.reconnect_failed', [10, 'again', $this->twitch]);

        $this->assertCount(1, $this->dms, 'one DM per outage');
        $dm = $this->dms[0]['sent'];
        $this->assertStringContainsString('Twitch is disconnected', $dm);
        $this->assertStringContainsString('<t:', $dm, 'says since when');
        $this->assertStringContainsString('after 10 attempts', $dm);
        $this->assertStringContainsString('every 5 minutes', $dm);
        $this->assertStringContainsString('bridge:reconnect:twitch', $dm);
        $this->assertStringNotContainsString('tcp://', $dm, 'the reason stays in the log');

        $this->twitch->emit('chat.connected', [$this->twitch]);

        $this->assertCount(1, $this->dms[0]['edits']);
        $this->assertStringContainsString('Twitch is back', $this->dms[0]['edits'][0]);
    }

    public function testChatComingUpWithoutAnOutageSaysNothing(): void
    {
        $this->watch();

        $this->twitch->emit('chat.connected', [$this->twitch]);

        $this->assertSame([], $this->dms);
    }

    public function testReconnectIsChatsReconnect(): void
    {
        $irc = (new \ReflectionClass(Irc::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Irc::class, 'connected'))->setValue($irc, true);
        (new \ReflectionProperty(Twitch::class, 'irc'))->setValue($this->twitch, $irc);

        $done = false;
        $this->connector->reconnect()->then(function () use (&$done): void {
            $done = true;
        });

        $this->assertTrue($done, 'already connected, so nothing to do');
    }

    public function testReconnectBeforeChatWasEverSetUpSaysSo(): void
    {
        $error = null;
        $this->connector->reconnect()->then(null, function (\Throwable $e) use (&$error): void {
            $error = $e->getMessage();
        });

        $this->assertSame('Twitch chat is not set up; it never started.', $error);
    }

    /** What start() does once chat is up, with DMs recorded rather than sent. */
    private function watch(): void
    {
        $alerts = new ConnectionAlerts($this->bot, function (MessageBuilder $message): PromiseInterface {
            $index = count($this->dms);
            $this->dms[] = ['sent' => self::json($message), 'edits' => []];

            return resolve(new class ($this->dms, $index) {
                /** @param array<int, array{sent: string, edits: list<string>}> $dms */
                public function __construct(private array &$dms, private readonly int $index)
                {
                }

                public function edit(MessageBuilder $message): PromiseInterface
                {
                    $this->dms[$this->index]['edits'][] = ConnectionAlertsTest::json($message);

                    return resolve($this);
                }
            });
        });

        (new \ReflectionMethod(TwitchConnector::class, 'watchConnection'))->invoke($this->connector, $alerts);
    }

    public static function json(MessageBuilder $message): string
    {
        return (string) json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
