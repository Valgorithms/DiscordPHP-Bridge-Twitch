<?php

namespace TwitchBot\Tests;

use PHPUnit\Framework\TestCase;
use TwitchBot\Store;

final class StoreTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/twitchrelay_test_' . getmypid() . '.json';
        @unlink($this->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testStartsEmpty(): void
    {
        self::assertTrue((new Store($this->path))->links()->isEmpty());
    }

    public function testLinkPersistsAcrossInstances(): void
    {
        (new Store($this->path))->link('100', '1', 'twitchdev');

        self::assertSame('twitchdev', (new Store($this->path))->links()->twitchFor('1'));
    }

    public function testLoginIsStoredLowercase(): void
    {
        $links = (new Store($this->path))->link('100', '1', 'TwitchDev');

        self::assertSame('twitchdev', $links->twitchFor('1'));
    }

    /** A channel points at one Twitch channel; setting it again replaces. */
    public function testRelinkingReplacesRatherThanAccumulates(): void
    {
        $store = new Store($this->path);
        $store->link('100', '1', 'twitchdev');
        $links = $store->link('100', '1', 'ninja');

        self::assertSame('ninja', $links->twitchFor('1'));
        self::assertSame(1, $links->count());
    }

    public function testUnlink(): void
    {
        $store = new Store($this->path);
        $store->link('100', '1', 'twitchdev');
        $links = $store->unlink('100', '1');

        self::assertNull($links->twitchFor('1'));
        self::assertTrue($links->isEmpty());
    }

    public function testUnlinkingSomethingUnbridgedIsANoOp(): void
    {
        $store = new Store($this->path);
        $store->link('100', '1', 'twitchdev');

        self::assertSame(1, $store->unlink('100', '999')->count());
    }

    public function testForgetGuildDropsOnlyThatGuild(): void
    {
        $store = new Store($this->path);
        $store->link('100', '1', 'twitchdev');
        $store->link('200', '2', 'ninja');

        $links = $store->forgetGuild('100');

        self::assertNull($links->twitchFor('1'));
        self::assertSame('ninja', $links->twitchFor('2'));
    }

    public function testEmptyGuildsArePrunedRatherThanLeftBehind(): void
    {
        $store = new Store($this->path);
        $store->link('100', '1', 'twitchdev');
        $store->unlink('100', '1');

        self::assertSame([], $store->toArray()['links'] ?? []);
    }

    public function testCorruptFileDoesNotCrashStartup(): void
    {
        file_put_contents($this->path, 'not json at all');

        self::assertTrue((new Store($this->path))->links()->isEmpty());
    }

    public function testWritesLeaveNoTempFilesBehind(): void
    {
        (new Store($this->path))->link('100', '1', 'twitchdev');

        self::assertSame([], glob($this->path . '.*.tmp') ?: []);
    }
}
