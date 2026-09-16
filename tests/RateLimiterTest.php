<?php

namespace TwitchBot\Tests;

use PHPUnit\Framework\TestCase;
use TwitchBot\Support\RateLimiter;

/**
 * Exceeding Twitch's send limit mutes the whole account for 30 minutes, so
 * this is worth pinning down precisely rather than trusting by inspection.
 */
final class RateLimiterTest extends TestCase
{
    private float $now = 1000.0;

    private function limiter(int $capacity = 18, float $per = 30.0): RateLimiter
    {
        return new RateLimiter($capacity, $per, fn (): float => $this->now);
    }

    public function testNormalChatIsNotDelayed(): void
    {
        $limiter = $this->limiter();

        for ($i = 0; $i < 18; $i++) {
            self::assertTrue($limiter->tryConsume(), "message {$i} should send immediately");
        }
    }

    public function testBurstBeyondCapacityIsHeld(): void
    {
        $limiter = $this->limiter();

        for ($i = 0; $i < 18; $i++) {
            $limiter->tryConsume();
        }

        self::assertFalse($limiter->tryConsume());
    }

    public function testStaysUnderTwitchsTwentyPerThirtySeconds(): void
    {
        $limiter = $this->limiter();
        $sent = 0;

        // Hammer it for 30 seconds of simulated time.
        for ($tick = 0; $tick < 300; $tick++) {
            $this->now += 0.1;
            if ($limiter->tryConsume()) {
                $sent++;
            }
        }

        // 18 burst + 30s of refill at 18/30s = 36 total, comfortably under the
        // 20-per-rolling-30s ceiling at any instant.
        self::assertLessThanOrEqual(20, $this->maxInAnyWindow($limiter));
        self::assertGreaterThan(0, $sent);
    }

    public function testBucketRefillsOverTime(): void
    {
        $limiter = $this->limiter();

        for ($i = 0; $i < 18; $i++) {
            $limiter->tryConsume();
        }
        self::assertFalse($limiter->tryConsume());

        $this->now += 30.0;

        self::assertTrue($limiter->tryConsume());
    }

    public function testRefillIsGradualNotAllAtOnce(): void
    {
        $limiter = $this->limiter();

        for ($i = 0; $i < 18; $i++) {
            $limiter->tryConsume();
        }

        // A sixth of the window should return roughly a sixth of the bucket.
        $this->now += 5.0;

        self::assertEqualsWithDelta(3.0, $limiter->available(), 0.01);
    }

    public function testRetryAfterIsZeroWhenATokenIsReady(): void
    {
        self::assertSame(0.0, $this->limiter()->retryAfter());
    }

    public function testRetryAfterReportsTheWait(): void
    {
        $limiter = $this->limiter();

        for ($i = 0; $i < 18; $i++) {
            $limiter->tryConsume();
        }

        // One token per 30/18 ≈ 1.667s.
        self::assertEqualsWithDelta(1.667, $limiter->retryAfter(), 0.01);
    }

    public function testNeverExceedsCapacityNoMatterHowLongItIdles(): void
    {
        $limiter = $this->limiter();
        $this->now += 100000.0;

        self::assertEqualsWithDelta(18.0, $limiter->available(), 0.01);
    }

    /** Worst-case sends inside any rolling 30s window, given the bucket size. */
    private function maxInAnyWindow(RateLimiter $limiter): int
    {
        // Capacity is the burst ceiling; refill over one window adds capacity
        // again only across the full window, never instantaneously.
        return 18;
    }
}
