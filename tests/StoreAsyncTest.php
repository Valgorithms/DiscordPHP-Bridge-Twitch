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
use TwitchBot\Store;
use TwitchBot\Support\Filesystem;
use TwitchBot\Tests\Doubles\DeferredAdapter;

/**
 * The store must not make the event loop wait for a disk.
 *
 * Every test here drives the asynchronous path through a backend that
 * completes only when told to — which is the path a host with `ext-uv` takes,
 * and the one a plain Windows host never exercises by itself.
 */
final class StoreAsyncTest extends TestCase
{
    private string $dir;

    private string $path;

    private DeferredAdapter $adapter;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/twitchbot-async-' . bin2hex(random_bytes(6));
        $this->path = $this->dir . '/bridges.json';
        $this->adapter = new DeferredAdapter();
        @mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    public function testLinkingReturnsWithoutWaitingForTheDisk(): void
    {
        $store = $this->store();

        $links = $store->link('guild1', 'chan1', 'twitchdev');

        // Answered from memory: the command can reply immediately.
        $this->assertSame('twitchdev', $links->twitchFor('chan1'));

        // ...while the write is still outstanding.
        $this->assertFileDoesNotExist($this->path);
        $this->assertSame(1, $this->adapter->pending());
    }

    public function testTheWriteLandsWhenTheBackendFinishes(): void
    {
        $store = $this->store();
        $store->link('guild1', 'chan1', 'twitchdev');

        $done = false;
        $store->saved()->then(function () use (&$done) {
            $done = true;
        });

        $this->assertFalse($done);

        $this->adapter->settle();

        $this->assertTrue($done);
        $this->assertFileExists($this->path);
        $this->assertSame('twitchdev', $this->reopen()->links()->twitchFor('chan1'));
    }

    public function testTheBackupIsWrittenOnTheAsyncPathToo(): void
    {
        $store = $this->store();
        $store->link('guild1', 'chan1', 'twitchdev');
        $this->adapter->settle();

        $this->assertFileExists($this->path . Store::BACKUP_SUFFIX);
        $this->assertSame(
            file_get_contents($this->path),
            file_get_contents($this->path . Store::BACKUP_SUFFIX),
        );
    }

    public function testChangesDuringAWriteCoalesceIntoOneMoreWrite(): void
    {
        $store = $this->store();

        $store->link('guild1', 'chan1', 'twitchdev');   // starts a write
        $store->link('guild1', 'chan2', 'someone');     // queued behind it
        $store->link('guild1', 'chan3', 'somebodyelse'); // folded into the same one

        $this->adapter->settle();

        // Two rounds of (file + backup), not three.
        $this->assertSame(4, $this->adapter->countOf('write'));
        $this->assertSame(3, $this->reopen()->links()->count());
    }

    public function testTheQueueKeepsWorkingAfterAWriteCompletes(): void
    {
        // The regression this suite exists for: the queue used to hold the
        // promise returned by then(), which a synchronous backend resolves
        // during registration — so the "in flight" slot was never cleared and
        // nothing was ever written again.
        $store = $this->store();

        $store->link('guild1', 'chan1', 'twitchdev');
        $this->adapter->settle();

        $store->link('guild1', 'chan2', 'someone');
        $this->adapter->settle();

        $this->assertSame(2, $this->reopen()->links()->count());
    }

    public function testSavedResolvesImmediatelyWhenNothingIsPending(): void
    {
        $resolved = false;
        $this->store()->saved()->then(function () use (&$resolved) {
            $resolved = true;
        });

        $this->assertTrue($resolved);
    }

    public function testSavedWaitsForACoalescedFollowUpToo(): void
    {
        $store = $this->store();
        $store->link('guild1', 'chan1', 'twitchdev');
        $store->link('guild1', 'chan2', 'someone');

        $done = false;
        $store->saved()->then(function () use (&$done) {
            $done = true;
        });

        $this->adapter->settle();

        $this->assertTrue($done);
        $this->assertSame(2, $this->reopen()->links()->count());
    }

    public function testFlushWritesEvenWithAnOutstandingAsyncWrite(): void
    {
        // Shutdown: the loop is about to stop, so a queued write would never
        // run. Flushing must put what is in memory on the disk regardless.
        $store = $this->store();
        $store->link('guild1', 'chan1', 'twitchdev');

        $this->assertFileDoesNotExist($this->path);
        $this->assertTrue($store->flush());

        $this->assertSame('twitchdev', $this->reopen()->links()->twitchFor('chan1'));
    }

    public function testFlushIsANoOpWhenNothingHasChanged(): void
    {
        $this->assertTrue($this->store()->flush());
        $this->assertFileDoesNotExist($this->path);
    }

    public function testNoTemporaryFilesAreLeftBehind(): void
    {
        $store = $this->store();
        $store->link('guild1', 'chan1', 'twitchdev');
        $this->adapter->settle();

        $this->assertSame([], glob($this->dir . '/*.tmp'));
    }

    public function testAFailedWriteLeavesTheLiveFileAlone(): void
    {
        // A directory where the temp file should go: the write fails, and the
        // configuration that was already on disk must survive it.
        $store = $this->store();
        $store->link('guild1', 'chan1', 'twitchdev');
        $this->adapter->settle();

        $good = (string) file_get_contents($this->path);

        @mkdir($this->path . '.' . getmypid() . '.tmp');

        $store->link('guild1', 'chan2', 'someone');
        $this->adapter->settle();

        $this->assertSame($good, file_get_contents($this->path));

        @rmdir($this->path . '.' . getmypid() . '.tmp');
    }

    private function store(): Store
    {
        return new Store($this->path, Filesystem::with($this->adapter, 'deferred'));
    }

    /** Reads the file back the way a restarted process would. */
    private function reopen(): Store
    {
        return new Store($this->path, Filesystem::blocking());
    }
}
