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

namespace TwitchBot\Relay;

use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use Twitch\Parts\ChatMessage;
use Twitch\Twitch;
use TwitchBot\Links;
use TwitchBot\Support\MessageText;
use TwitchBot\Support\RateLimiter;

/**
 * Owns the Twitch side of the bridge: one IRC connection, the set of channels
 * it is joined to, and a paced outbound queue.
 *
 * One connection serves every guild. Two servers following the same streamer
 * share a single JOIN — {@see Links::logins()} is already deduplicated — and
 * {@see sync()} moves the connection to a new set of channels with a diff
 * rather than a reconnect, so reconfiguring one guild does not interrupt the
 * others.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class TwitchGateway
{
    /** @var list<string> Channels currently joined, lower-case, no leading '#'. */
    private array $joined = [];

    /** @var list<array{channel: string, text: string, replyTo: string|null}> */
    private array $queue = [];

    /** @var array<string, RateLimiter> Per-channel budget. */
    private array $limiters = [];

    private bool $draining = false;

    /** @var (callable(ChatMessage): void)|null */
    private $onChat = null;

    public function __construct(
        private readonly Twitch $twitch,
        private readonly LoopInterface $loop,
        private readonly LoggerInterface $logger,
        private readonly string $nick,
    ) {
    }

    /** Registers the handler for inbound Twitch chat. */
    public function onChat(callable $handler): void
    {
        $this->onChat = $handler;
    }

    /**
     * Starts listening. Messages the bridge itself sent are dropped here — the
     * bot sees its own PRIVMSGs echoed back, and relaying those would bounce
     * every Discord message straight back into Discord.
     */
    public function listen(): void
    {
        $this->twitch->on('chat', function (ChatMessage $message): void {
            if (strcasecmp($message->user, $this->nick) === 0) {
                return;
            }

            if ($this->onChat !== null) {
                ($this->onChat)($message);
            }
        });

        $this->twitch->on('chat.connected', function (): void {
            $this->logger->info('[twitch] chat connected as ' . $this->nick);

            // A reconnect drops every JOIN, so re-issue them.
            $rejoin = $this->joined;
            $this->joined = [];
            $this->applyJoins($rejoin);
        });

        $this->twitch->on('chat.disconnected', function (int $code, string $reason): void {
            $this->logger->warning(sprintf('[twitch] chat disconnected (%d) %s', $code, $reason));
        });
    }

    /**
     * Brings the joined set in line with the routing table.
     *
     * @return array{join: list<string>, part: list<string>} what actually changed
     */
    public function sync(Links $links): array
    {
        $want = $links->logins();
        $join = array_values(array_diff($want, $this->joined));
        $part = array_values(array_diff($this->joined, $want));

        $irc = $this->twitch->getIrc();
        if ($irc === null) {
            // Not connected yet; remember the target so chat.connected applies it.
            $this->joined = $want;

            return ['join' => $join, 'part' => $part];
        }

        foreach ($part as $login) {
            $irc->part($login);
            unset($this->limiters[$login]);
            $this->logger->info('[twitch] parted #' . $login);
        }

        $this->joined = array_values(array_diff($this->joined, $part));
        $this->applyJoins($join);

        return ['join' => $join, 'part' => $part];
    }

    /**
     * Queues a message for a Twitch channel.
     *
     * Never sends inline: Twitch mutes the *account* for 30 minutes if the
     * send limit is exceeded, so everything goes through the per-channel
     * bucket even when the bucket is full and the queue is empty.
     *
     * Command replies come through here too, not just relayed chat. Both speak
     * as the same account against the same limit, and two senders that each
     * stay under it will still breach it together.
     *
     * @param string|null $replyTo An IRCv3 message id to thread the reply onto.
     */
    public function send(string $channel, string $text, ?string $replyTo = null): void
    {
        $channel = strtolower(ltrim($channel, '#'));
        $text = MessageText::sanitizeIrc($text);

        if ($text === '' || ! in_array($channel, $this->joined, true)) {
            return;
        }

        $this->queue[] = ['channel' => $channel, 'text' => $text, 'replyTo' => $replyTo === '' ? null : $replyTo];
        $this->drain();
    }

    /** How many messages are waiting, for logging and health checks. */
    public function queued(): int
    {
        return count($this->queue);
    }

    /** @param list<string> $logins */
    private function applyJoins(array $logins): void
    {
        $irc = $this->twitch->getIrc();

        foreach ($logins as $login) {
            if (in_array($login, $this->joined, true)) {
                continue;
            }

            $irc?->join($login);
            $this->joined[] = $login;
            $this->logger->info('[twitch] joined #' . $login);
        }
    }

    /**
     * Sends whatever the budget allows, then re-arms a timer for the rest.
     * Head-of-line blocking is avoided by skipping over a channel that is out
     * of tokens instead of stalling the whole queue behind it.
     */
    private function drain(): void
    {
        $irc = $this->twitch->getIrc();
        if ($irc === null) {
            return;
        }

        $deferred = [];
        $soonest = null;

        while ($this->queue !== []) {
            $item = array_shift($this->queue);
            $limiter = $this->limiters[$item['channel']] ??= new RateLimiter();

            if ($limiter->tryConsume()) {
                $irc->say($item['channel'], $item['text'], $item['replyTo']);

                continue;
            }

            $deferred[] = $item;
            $wait = $limiter->retryAfter();
            $soonest = $soonest === null ? $wait : min($soonest, $wait);
        }

        $this->queue = $deferred;

        if ($this->queue === [] || $this->draining) {
            return;
        }

        $this->draining = true;
        $this->loop->addTimer(max(0.1, $soonest ?? 0.1), function (): void {
            $this->draining = false;
            $this->drain();
        });
    }
}
