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

use Bridge\Links;
use Bridge\Support\RateLimiter;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use Twitch\Parts\ChatMessage;
use Twitch\Twitch;

/**
 * Owns the Twitch side of the bridge: one IRC connection, the set of channels
 * it is joined to, and a paced outbound queue.
 *
 * One connection serves every guild. Two servers following the same streamer
 * share a single JOIN — {@see Links::targets()} is already deduplicated — and
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

    /**
     * Twitch's chat limit for an account that is not a moderator: 20 messages
     * per 30 seconds, counted across *every* channel it speaks in, not per
     * channel. Kept a little under, since a breach mutes the account.
     */
    public const CAPACITY = 18;

    public const PER = 30.0;

    /**
     * How much may wait. A busy Discord channel produces more than Twitch will
     * take — Discord allows a message a second, Twitch fewer than one every
     * 1.6 — so without a bound the backlog grows for as long as the
     * conversation lasts, and is still being played out long after it ended.
     */
    public const MAX_QUEUE = 100;

    /** The account's one send budget. */
    private readonly RateLimiter $budget;

    /** How many messages were dropped since the backlog last cleared. */
    private int $dropped = 0;

    private bool $draining = false;

    /** @var (callable(ChatMessage): void)|null */
    private $onChat = null;

    public function __construct(
        private readonly Twitch $twitch,
        private readonly LoopInterface $loop,
        private readonly LoggerInterface $logger,
        private readonly string $nick,
        ?RateLimiter $budget = null,
    ) {
        $this->budget = $budget ?? new RateLimiter(self::CAPACITY, self::PER);
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
        $want = $links->targets();
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
            $this->logger->info('[twitch] parted #' . $login);
        }

        // Nothing still waiting for a channel the bot has just left.
        $this->queue = array_values(array_filter(
            $this->queue,
            static fn (array $item): bool => ! in_array($item['channel'], $part, true),
        ));

        $this->joined = array_values(array_diff($this->joined, $part));
        $this->applyJoins($join);

        return ['join' => $join, 'part' => $part];
    }

    /**
     * Queues a message for a Twitch channel.
     *
     * Never sends around the budget: Twitch mutes the *account* for 30
     * minutes if the send limit is exceeded, so everything goes through the
     * one account-wide bucket, whichever channel it is for.
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
        $text = TwitchText::sanitizeIrc($text);

        if ($text === '' || ! in_array($channel, $this->joined, true)) {
            return;
        }

        if (count($this->queue) >= self::MAX_QUEUE) {
            // The oldest goes: by the time it could be sent the conversation
            // it belonged to has moved on.
            array_shift($this->queue);

            if ($this->dropped++ === 0) {
                $this->logger->warning(sprintf(
                    '[twitch] more is waiting than Twitch will take (%d messages); dropping the oldest until it clears',
                    self::MAX_QUEUE,
                ));
            }
        }

        $this->queue[] = ['channel' => $channel, 'text' => $text, 'replyTo' => $replyTo === '' ? null : $replyTo];
        $this->drain();
    }

    /**
     * The channels this connection is currently in, lower-case.
     *
     * Re-established on every start from the routing table, so the startup
     * check compares it against what was restored from disk rather than
     * assuming the JOINs landed.
     *
     * @return list<string>
     */
    public function joined(): array
    {
        return $this->joined;
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
     * Sends whatever the budget allows, in order, then re-arms a timer for the
     * rest.
     */
    private function drain(): void
    {
        $irc = $this->twitch->getIrc();
        if ($irc === null) {
            return;
        }

        while ($this->queue !== [] && $this->budget->tryConsume()) {
            $item = array_shift($this->queue);
            $irc->say($item['channel'], $item['text'], $item['replyTo']);
        }

        if ($this->queue === []) {
            if ($this->dropped > 0) {
                $this->logger->info(sprintf('[twitch] backlog cleared; %d message(s) were dropped', $this->dropped));
                $this->dropped = 0;
            }

            return;
        }

        if ($this->draining) {
            return;
        }

        $this->draining = true;
        $this->loop->addTimer(max(0.1, $this->budget->retryAfter()), function (): void {
            $this->draining = false;
            $this->drain();
        });
    }
}
