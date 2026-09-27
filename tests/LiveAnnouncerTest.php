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

use Bridge\Support\Filesystem;
use Bridge\Support\JsonFile;
use Bridge\Twitch\LiveAnnouncer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use React\EventLoop\StreamSelectLoop;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Going live and ending, said once each, in the bridged channels: not twice
 * for a restart, not for an encoder blip, and not an hour late.
 */
final class LiveAnnouncerTest extends TestCase
{
    private string $dir;

    private float $now = 1_000_000.0;

    /** @var list<string> */
    private array $bridged = ['streamer'];

    /** @var array<string, array{id: string, login: string, name: string, title: string, game: string, started_at: int}> */
    private array $liveNow = [];

    /** @var list<array{login: string, text: string}> */
    private array $said = [];

    /** @var list<list<string>> The logins each request asked about. */
    private array $requests = [];

    private bool $twitchIsDown = false;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/bridge-twitch-live-' . bin2hex(random_bytes(6));
        @mkdir($this->dir, 0o777, true);
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

    public function testGoingLiveIsSaidOnce(): void
    {
        $announcer = $this->announcer();
        $this->goLive('s1', title: 'Building a bridge', game: 'Software and Game Development');

        $announcer->check();
        $this->later();
        $announcer->check();

        $this->assertCount(1, $this->said);
        $this->assertSame('streamer', $this->said[0]['login']);
        $this->assertSame(
            "🔴 **Live now:** Building a bridge\nStreaming Software and Game Development\nhttps://www.twitch.tv/streamer",
            $this->said[0]['text'],
        );
    }

    public function testTheEndIsSaidAfterTwoChecksWithoutTheStream(): void
    {
        $announcer = $this->announcer();
        // On air for an hour and four minutes already.
        $this->goLive('s1', startedAt: (int) $this->now - 3840);
        $announcer->check();

        $this->later();
        $this->liveNow = [];
        $announcer->check();
        $this->assertCount(1, $this->said, 'one miss could be an encoder blip');

        $this->later();
        $announcer->check();

        $this->assertCount(2, $this->said);
        $this->assertSame('⚫ **Stream ended** after 1h 6m.', $this->said[1]['text']);
        $this->assertSame([], $announcer->live());
    }

    public function testAStreamBackWithinTheGraceWindowIsTheSameStream(): void
    {
        $announcer = $this->announcer();
        $this->goLive('s1');
        $announcer->check();

        $this->later();
        $this->liveNow = [];
        $announcer->check();

        // Back, under a new id.
        $this->later();
        $this->goLive('s2', startedAt: (int) $this->now);
        $announcer->check();

        $this->assertCount(1, $this->said, 'not announced as live a second time');

        // When it does end, its length counts from the first start.
        $this->liveNow = [];
        foreach ([1, 2] as $check) {
            $this->later();
            $announcer->check();
        }
        $this->assertSame('⚫ **Stream ended** after 5m.', $this->said[1]['text']);
    }

    public function testARestartMidStreamDoesNotAnnounceItAgain(): void
    {
        $this->goLive('s1');
        $first = $this->announcer();
        $first->check();
        $first->stop();

        $this->later();
        $this->announcer()->check();

        $this->assertCount(1, $this->said);
    }

    public function testAStreamThatEndedDuringAShortRestartIsStillAnnounced(): void
    {
        $this->goLive('s1');
        $first = $this->announcer();
        $first->check();
        $first->stop();

        $this->liveNow = [];
        $this->later();
        $second = $this->announcer();
        $second->check();
        $this->later();
        $second->check();

        $this->assertCount(2, $this->said);
        $this->assertStringContainsString('Stream ended', $this->said[1]['text']);
    }

    public function testAStreamThatEndedWhileTheBotWasDownIsLetGoQuietly(): void
    {
        $this->goLive('s1');
        $first = $this->announcer();
        $first->check();
        $first->stop();

        // An hour offline, and the stream finished meanwhile.
        $this->now += 3600;
        $this->liveNow = [];
        $second = $this->announcer();
        $second->check();
        $this->later();
        $second->check();

        $this->assertCount(1, $this->said, 'no "stream ended" an hour late');
        $this->assertSame([], $second->live());
    }

    public function testAChannelThatIsNoLongerBridgedIsForgotten(): void
    {
        $announcer = $this->announcer();
        $this->goLive('s1');
        $announcer->check();

        $this->bridged = [];
        foreach ([1, 2] as $check) {
            $this->later();
            $announcer->check();
        }

        $this->assertCount(1, $this->said, 'unbridging is not the stream ending');
        $this->assertSame([], $announcer->live());
    }

    public function testManyChannelsAreAskedAboutAHundredAtATime(): void
    {
        $this->bridged = array_map(static fn (int $i): string => 'channel' . $i, range(1, 150));

        $this->announcer()->check();

        $this->assertSame([100, 50], array_map('count', $this->requests));
    }

    public function testAFailedCheckChangesNothing(): void
    {
        $announcer = $this->announcer();
        $this->goLive('s1');
        $announcer->check();

        $this->twitchIsDown = true;
        foreach ([1, 2, 3] as $check) {
            $this->later();
            $announcer->check();
        }

        $this->assertCount(1, $this->said, 'Twitch being unreachable is not the stream ending');
        $this->assertArrayHasKey('streamer', $announcer->live());
    }

    public function testATitleIsShownAsTyped(): void
    {
        $text = LiveAnnouncer::liveText([
            'login' => 'streamer',
            'title' => "**big**   _news_\nsecond line",
            'game' => '',
        ]);

        $this->assertSame("🔴 **Live now:** \\*\\*big\\*\\* \\_news\\_ second line\nhttps://www.twitch.tv/streamer", $text);
    }

    private function announcer(): LiveAnnouncer
    {
        return new LiveAnnouncer(
            new StreamSelectLoop(),
            new NullLogger(),
            new JsonFile($this->dir . '/twitch-live.json', Filesystem::blocking()),
            fn (): array => $this->bridged,
            function (array $logins): PromiseInterface {
                $this->requests[] = $logins;
                if ($this->twitchIsDown) {
                    return reject(new \RuntimeException('Twitch is down'));
                }

                return resolve(array_values(array_filter(
                    $this->liveNow,
                    static fn (array $stream): bool => in_array($stream['login'], $logins, true),
                )));
            },
            function (string $login, string $text): void {
                $this->said[] = ['login' => $login, 'text' => $text];
            },
            fn (): float => $this->now,
        );
    }

    private function goLive(string $id, string $title = 'Hello', string $game = '', ?int $startedAt = null): void
    {
        $this->liveNow['streamer'] = [
            'id' => $id,
            'login' => 'streamer',
            'name' => 'Streamer',
            'title' => $title,
            'game' => $game,
            'started_at' => $startedAt ?? (int) $this->now - 60,
        ];
    }

    /** The next check's time. */
    private function later(): void
    {
        $this->now += LiveAnnouncer::INTERVAL;
    }
}
