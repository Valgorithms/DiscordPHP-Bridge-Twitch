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

use Bridge\Links;
use Bridge\Support\RateLimiter;
use Bridge\Twitch\TwitchGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ratchet\Client\WebSocket;
use React\EventLoop\StreamSelectLoop;
use Twitch\Chat\Irc;
use Twitch\Twitch;

/**
 * One send budget for the whole account, shared fairly.
 *
 * What goes out is read off the socket itself, so these check the lines Twitch
 * would actually receive. The clock is injected and only moves when a test
 * moves it.
 */
final class TwitchGatewayTest extends TestCase
{
    /** @var list<string> */
    private array $lines = [];

    private float $now = 1000.0;

    public function testOneFloodedChannelDoesNotStarveAnother(): void
    {
        // A viewer spamming commands in one channel, or one very busy bridge,
        // must not push everyone else out of the shared budget.
        $gateway = $this->gateway(capacity: 2);

        for ($i = 0; $i < 5; ++$i) {
            $gateway->send('busy', 'flood ' . $i);
        }

        $gateway->send('quiet', 'hello');

        // Two went out at once; three of busy's and quiet's one are waiting.
        $this->assertSame(['#busy', '#busy'], $this->channelsSaid());

        $this->now += 30.0;
        $this->drain($gateway);

        // Turns, not arrival order: quiet does not wait behind busy's backlog.
        $this->assertSame(['#busy', '#busy', '#busy', '#quiet'], $this->channelsSaid());
    }

    public function testEachChannelsOwnMessagesStayInOrder(): void
    {
        $gateway = $this->gateway(capacity: 4);

        foreach (['one', 'two', 'three'] as $text) {
            $gateway->send('busy', $text);
        }

        $this->assertSame(
            ['PRIVMSG #busy :one', 'PRIVMSG #busy :two', 'PRIVMSG #busy :three'],
            $this->privmsgs(),
        );
    }

    public function testTheBacklogIsTrimmedFromTheChannelCausingIt(): void
    {
        $gateway = $this->gateway(capacity: 2);
        $gateway->send('busy', 'spent');
        $gateway->send('busy', 'the budget');

        for ($i = 0; $i < TwitchGateway::MAX_QUEUE; ++$i) {
            $gateway->send('busy', 'flood ' . $i);
        }

        $gateway->send('quiet', 'still here');

        // Full, and the one that made room was busy's, not quiet's.
        $this->assertSame(TwitchGateway::MAX_QUEUE, $gateway->queued());

        $this->now += 30.0;
        $this->drain($gateway);

        $this->assertContains('PRIVMSG #quiet :still here', $this->privmsgs());
        $this->assertNotContains('PRIVMSG #busy :flood 0', $this->privmsgs());
    }

    public function testNothingWaitsForAChannelThatWasLeft(): void
    {
        $gateway = $this->gateway(capacity: 1);
        $gateway->send('busy', 'spent the budget');
        $gateway->send('busy', 'waiting');

        $gateway->sync(new Links(['111111111111' => ['222222222222' => 'quiet']]));

        $this->assertSame(0, $gateway->queued());
    }

    private function gateway(int $capacity): TwitchGateway
    {
        $socket = $this->createMock(WebSocket::class);
        $socket->method('send')->willReturnCallback(function (string $line): void {
            $this->lines[] = rtrim($line, "\r\n");
        });

        $irc = (new \ReflectionClass(Irc::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Irc::class, 'conn'))->setValue($irc, $socket);

        $twitch = (new \ReflectionClass(Twitch::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Twitch::class, 'irc'))->setValue($twitch, $irc);

        $gateway = new TwitchGateway(
            $twitch,
            new StreamSelectLoop(),
            new NullLogger(),
            'bridgebot',
            new RateLimiter($capacity, 30.0, fn (): float => $this->now),
        );

        $gateway->sync(new Links(['111111111111' => [
            '222222222222' => 'busy',
            '333333333333' => 'quiet',
        ]]));

        return $gateway;
    }

    /** What the timer the gateway armed would do, without running a loop. */
    private function drain(TwitchGateway $gateway): void
    {
        (new \ReflectionMethod($gateway, 'drain'))->invoke($gateway);
    }

    /** @return list<string> */
    private function privmsgs(): array
    {
        return array_values(array_filter($this->lines, static fn (string $line): bool => str_starts_with($line, 'PRIVMSG')));
    }

    /** @return list<string> */
    private function channelsSaid(): array
    {
        return array_map(static fn (string $line): string => explode(' ', $line)[1], $this->privmsgs());
    }
}
