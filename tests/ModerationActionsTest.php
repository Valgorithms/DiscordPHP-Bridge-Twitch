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
use Bridge\Command\Context;
use Bridge\Command\Surface;
use Bridge\Config;
use Bridge\Environment;
use Bridge\Store;
use Bridge\Support\Filesystem;
use Bridge\Twitch\Actions\ModerationActions;
use Bridge\Twitch\TwitchConnector;
use Bridge\Twitch\TwitchText;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use React\EventLoop\StreamSelectLoop;

/**
 * What an announcement says about who sent it.
 *
 * Twitch shows every announcement as the host's, so one typed anywhere but
 * Twitch chat has to carry its own attribution.
 */
final class ModerationActionsTest extends TestCase
{
    private string $dir;

    private Bot $bot;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bridge-twitch-' . bin2hex(random_bytes(6));
        @mkdir($this->dir, 0o777, true);

        $path = $this->dir . '/bridges.json';
        $this->bot = new Bot(
            Config::fromEnvironment(Environment::fromArray(['DISCORD_TOKEN' => 'test.token.here']), $path),
            new Store($path, Filesystem::blocking()),
            // Nothing here resolves a name; see the core's BuildsBot.
            ['logger' => new NullLogger(), 'loop' => new StreamSelectLoop(), 'dnsConfig' => '8.8.8.8', 'socket_options' => ['dns' => '8.8.8.8']],
        );
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

    public function testAnAnnouncementFromAnotherNetworkSaysWhoAndWhere(): void
    {
        $this->assertSame('Alice (discord): Going live', $this->attributed(Surface::discord(), 'Alice', 'Going live'));
        $this->assertSame('Bob (telegram): Going live', $this->attributed($this->telegram(), 'Bob', 'Going live'));
    }

    public function testAnAnnouncementTypedInTwitchIsLeftAlone(): void
    {
        // The command that made it is right above it in chat.
        $this->assertSame('Going live', $this->attributed($this->twitch(), 'Alice', 'Going live'));
    }

    public function testANameCannotTurnTheAnnouncementIntoACommand(): void
    {
        // F2: a chat bot reading the line must not see a command at its start.
        $this->assertSame('addcom (discord): hi', $this->attributed(Surface::discord(), '!addcom', 'hi'));
    }

    public function testTheLabelDoesNotPushItPastTwitchsLimit(): void
    {
        $announced = $this->attributed(Surface::discord(), 'Alice', str_repeat('a', TwitchText::LIMIT));

        $this->assertLessThanOrEqual(TwitchText::LIMIT, mb_strlen($announced));
        $this->assertStringStartsWith('Alice (discord): ', $announced);
    }

    private function attributed(Surface $surface, string $name, string $text): string
    {
        $context = new Context($this->bot, $surface, Access::Moderator, $name, '1', connector: TwitchConnector::NAME, target: 'coffeescrafts');

        return (new \ReflectionMethod(ModerationActions::class, 'attributed'))->invoke(null, $context, $text);
    }

    private function twitch(): Surface
    {
        return new Surface(TwitchConnector::NAME, 'Twitch', TwitchText::LIMIT, markdown: false, lines: false);
    }

    private function telegram(): Surface
    {
        return new Surface('telegram', 'Telegram', 4096, markdown: false);
    }
}
