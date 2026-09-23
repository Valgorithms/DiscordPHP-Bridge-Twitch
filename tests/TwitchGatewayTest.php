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
use Twitch\Parts\ChatMessage;
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

    private Twitch $twitch;

    /** @var list<string> What reached the chat handler. */
    private array $heard = [];

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

    public function testTheHostsOwnChatIsRelayed(): void
    {
        // The bot speaks as the host's account, so a message from that account
        // is usually the host typing — the one person whose chat most needs to
        // reach Discord.
        $gateway = $this->gateway(capacity: 4);
        $gateway->listen();

        $this->hear('BridgeBot', 'busy', 'back in five');

        $this->assertSame(['back in five'], $this->heard);
    }

    public function testALineTheBridgeSentIsNotRelayedBack(): void
    {
        $gateway = $this->gateway(capacity: 4);
        $gateway->listen();
        $gateway->send('busy', 'alice: hi');

        $this->hear('bridgebot', 'busy', 'alice: hi');
        $this->assertSame([], $this->heard);

        // Each sent line matches once: the host saying the same thing is
        // still the host.
        $this->hear('bridgebot', 'busy', 'alice: hi');
        $this->assertSame(['alice: hi'], $this->heard);
    }

    public function testAnEchoIsMatchedOnlyInItsOwnChannelAndOnlyBriefly(): void
    {
        $gateway = $this->gateway(capacity: 4);
        $gateway->listen();
        $gateway->send('busy', 'alice: hi');
        $gateway->send('busy', 'bob: hey');

        $this->hear('bridgebot', 'quiet', 'alice: hi');

        $this->now += TwitchGateway::ECHO_WINDOW + 1.0;
        $this->hear('bridgebot', 'busy', 'bob: hey');

        $this->assertSame(['alice: hi', 'bob: hey'], $this->heard);
    }

    public function testSomebodyElseIsNeverTakenForAnEcho(): void
    {
        $gateway = $this->gateway(capacity: 4);
        $gateway->listen();
        $gateway->send('busy', 'alice: hi');

        $this->hear('alice', 'busy', 'alice: hi');

        $this->assertSame(['alice: hi'], $this->heard);
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
        $this->twitch = $twitch;

        $gateway = new TwitchGateway(
            $twitch,
            new StreamSelectLoop(),
            new NullLogger(),
            'bridgebot',
            new RateLimiter($capacity, 30.0, fn (): float => $this->now),
            fn (): float => $this->now,
        );
        $gateway->onChat(function (ChatMessage $message): void {
            $this->heard[] = (string) $message->content;
        });

        $gateway->sync(new Links(['111111111111' => [
            '222222222222' => 'busy',
            '333333333333' => 'quiet',
        ]]));

        return $gateway;
    }

    /** A line arriving from Twitch, as the IRC client would emit it. */
    private function hear(string $user, string $channel, string $text): void
    {
        $this->twitch->emit('chat', [new ChatMessage($this->twitch, [
            'id' => 'msg-' . count($this->heard),
            'channel' => $channel,
            'user' => $user,
            'content' => $text,
            'tags' => [],
        ]), $this->twitch]);
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
