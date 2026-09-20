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

namespace TwitchBot\Tests;

use PHPUnit\Framework\TestCase;
use TwitchBot\Bot;
use TwitchBot\Support\BridgeCheck;

final class BridgeCheckTest extends TestCase
{
    public function testAWorkingBridgeIsNotReported(): void
    {
        $this->assertNull(BridgeCheck::describe($this->row()));
    }

    public function testAMissingDiscordChannelSaysWhereMessagesWouldGo(): void
    {
        $problem = (string) BridgeCheck::describe($this->row(channel_ok: false));

        $this->assertStringContainsString('chan1', $problem);
        $this->assertStringContainsString('twitchdev', $problem);
        $this->assertStringContainsString('Discord channel', $problem);
    }

    public function testARenamedStreamerIsReported(): void
    {
        $problem = (string) BridgeCheck::describe($this->row(twitch_ok: false));

        $this->assertStringContainsString('renamed', $problem);
    }

    public function testAChannelThatExistsButWasNotJoinedIsReported(): void
    {
        // The one thing a restart is specifically supposed to re-establish: a
        // JOIN that silently failed leaves a bridge that works one way only.
        $problem = (string) BridgeCheck::describe($this->row(joined: false));

        $this->assertStringContainsString('not in its chat', $problem);
    }

    public function testBothEndsGoneIsOneLineNotTwo(): void
    {
        $problem = (string) BridgeCheck::describe($this->row(channel_ok: false, twitch_ok: false));

        $this->assertStringContainsString('neither end', $problem);
    }

    public function testTheSummaryCountsWhatWorksAndNamesWhatDoesnt(): void
    {
        $summary = BridgeCheck::summarise([
            $this->row(),
            $this->row(channel_ok: false),
            $this->row(),
            $this->row(joined: false),
        ]);

        $this->assertSame(2, $summary['healthy']);
        $this->assertCount(2, $summary['problems']);
    }

    public function testAnEmptySetIsHealthy(): void
    {
        $this->assertSame(['healthy' => 0, 'problems' => []], BridgeCheck::summarise([]));
    }

    public function testTheRestoredLineNamesTheFileItCameFrom(): void
    {
        $line = BridgeCheck::restored(3, 2, '/srv/bot/var/bridges.json');

        $this->assertStringContainsString('3 bridges', $line);
        $this->assertStringContainsString('2 servers', $line);
        $this->assertStringContainsString('/srv/bot/var/bridges.json', $line);
    }

    public function testTheRestoredLineIsSingularForOne(): void
    {
        $this->assertStringContainsString('1 bridge across 1 server', BridgeCheck::restored(1, 1, 'bridges.json'));
    }

    public function testAFreshInstallSaysSoRatherThanReportingZero(): void
    {
        $this->assertStringContainsString('no bridges configured yet', BridgeCheck::restored(0, 0, 'bridges.json'));
    }

    public function testTheProbeIsDelayed(): void
    {
        // Probing the instant modules boot reports channels as missing that
        // are merely not cached yet, and JOINs still in flight as failed.
        $this->assertGreaterThanOrEqual(5.0, Bot::BRIDGE_CHECK_DELAY);
    }

    /** @return array{channel_id: string, login: string, channel_ok: bool, twitch_ok: bool, joined: bool} */
    private function row(bool $channel_ok = true, bool $twitch_ok = true, bool $joined = true): array
    {
        return [
            'channel_id' => 'chan1',
            'login' => 'twitchdev',
            'channel_ok' => $channel_ok,
            'twitch_ok' => $twitch_ok,
            'joined' => $joined,
        ];
    }
}
