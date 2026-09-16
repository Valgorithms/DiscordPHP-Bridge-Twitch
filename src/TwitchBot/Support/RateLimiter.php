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

namespace TwitchBot\Support;

/**
 * A token bucket, sized for Twitch's IRC send limits.
 *
 * Twitch allows an ordinary account 20 messages per 30 seconds per channel;
 * exceeding it gets the *account* muted for 30 minutes, not merely the
 * message dropped. A busy Discord channel will happily exceed that, so the
 * bridge has to pace itself rather than find out.
 *
 * A bucket rather than a fixed delay: normal chat goes out immediately, and
 * only a genuine burst is slowed. The clock is injected so the behaviour can
 * be tested without sleeping.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class RateLimiter
{
    private float $tokens;

    private float $updatedAt;

    /** @var (callable(): float) */
    private $clock;

    /**
     * @param int                   $capacity How many messages may burst.
     * @param float                 $per      Over how many seconds the bucket refills.
     * @param (callable(): float)|null $clock  Defaults to `microtime(true)`.
     */
    public function __construct(
        private readonly int $capacity = 18,
        private readonly float $per = 30.0,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->tokens = (float) $capacity;
        $this->updatedAt = ($this->clock)();
    }

    /**
     * Takes one token if any is available.
     *
     * Deliberately capacity 18 against Twitch's 20: the bridge is not the only
     * thing that might speak as this account, and being muted for 30 minutes
     * is a far worse failure than a message arriving a second late.
     */
    public function tryConsume(): bool
    {
        $this->refill();

        if ($this->tokens < 1.0) {
            return false;
        }

        $this->tokens -= 1.0;

        return true;
    }

    /** Seconds until the next token is available; `0.0` when one is ready now. */
    public function retryAfter(): float
    {
        $this->refill();

        if ($this->tokens >= 1.0) {
            return 0.0;
        }

        return (1.0 - $this->tokens) * ($this->per / $this->capacity);
    }

    /** Tokens currently available, for logging and tests. */
    public function available(): float
    {
        $this->refill();

        return $this->tokens;
    }

    private function refill(): void
    {
        $now = ($this->clock)();
        $elapsed = $now - $this->updatedAt;

        if ($elapsed <= 0) {
            return;
        }

        $this->tokens = min((float) $this->capacity, $this->tokens + $elapsed * ($this->capacity / $this->per));
        $this->updatedAt = $now;
    }
}
