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
use React\Filesystem\Fallback;
use TwitchBot\Support\Filesystem;
use TwitchBot\Tests\Doubles\DeferredAdapter;

/**
 * Both backends have to behave identically from the outside, on every
 * platform — including Windows, where neither async extension is usually
 * installed.
 */
final class FilesystemTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/twitchbot-fs-' . bin2hex(random_bytes(6));
        @mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    public function testTheBackendIsChosenAtRuntimeAndNamed(): void
    {
        $filesystem = Filesystem::create();

        $this->assertContains($filesystem->adapter(), [
            Filesystem::ADAPTER_EIO,
            Filesystem::ADAPTER_UV,
            Filesystem::ADAPTER_BLOCKING,
        ]);

        // Whichever it picked, it must agree with itself about what that means.
        $this->assertSame(
            $filesystem->adapter() !== Filesystem::ADAPTER_BLOCKING,
            $filesystem->isAsynchronous(),
        );
    }

    public function testWithoutAnAsyncExtensionItSaysSoRatherThanPretending(): void
    {
        // react/filesystem's own fallback adapter is file_get_contents() in a
        // promise; reporting that as "asynchronous" would be a lie that costs
        // somebody a day of debugging.
        $blocking = Filesystem::blocking();

        $this->assertFalse($blocking->isAsynchronous());
        $this->assertStringContainsString('synchronous', $blocking->describe());
        $this->assertStringContainsString('php-uv', $blocking->describe());
    }

    public function testAnAsyncBackendDescribesItself(): void
    {
        $filesystem = Filesystem::with(new Fallback\Adapter(), 'test');

        $this->assertTrue($filesystem->isAsynchronous());
        $this->assertStringContainsString('asynchronous', $filesystem->describe());
    }

    /**
     * Every behaviour below is asserted against both paths, because the whole
     * point is that callers cannot tell them apart.
     *
     * @return iterable<string, array{Filesystem}>
     */
    public static function backends(): iterable
    {
        yield 'blocking' => [Filesystem::blocking()];
        yield 'react/filesystem adapter' => [Filesystem::with(new Fallback\Adapter(), 'test')];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('backends')]
    public function testWriteThenRead(Filesystem $filesystem): void
    {
        $path = $this->dir . '/round-trip.json';
        $written = null;
        $read = null;

        $filesystem->write($path, '{"hello":"world"}')->then(function (bool $ok) use (&$written) {
            $written = $ok;
        });
        $filesystem->read($path)->then(function (?string $contents) use (&$read) {
            $read = $contents;
        });

        $this->assertTrue($written);
        $this->assertSame('{"hello":"world"}', $read);
        $this->assertSame('{"hello":"world"}', file_get_contents($path));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('backends')]
    public function testReadingSomethingThatIsNotThereResolvesNullWithoutAWarning(Filesystem $filesystem): void
    {
        // The fallback adapter hands a missing path straight to
        // file_get_contents(), which warns and resolves false; the wrapper
        // checks first so neither happens.
        $read = 'untouched';

        $filesystem->read($this->dir . '/missing.json')->then(function (?string $contents) use (&$read) {
            $read = $contents;
        });

        $this->assertNull($read);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('backends')]
    public function testWritingCreatesMissingDirectories(Filesystem $filesystem): void
    {
        $path = $this->dir . '/nested/deeper/config.json';
        $ok = false;

        $filesystem->write($path, 'x')->then(function (bool $written) use (&$ok) {
            $ok = $written;
        });

        $this->assertTrue($ok);
        $this->assertFileExists($path);

        @unlink($path);
        @rmdir($this->dir . '/nested/deeper');
        @rmdir($this->dir . '/nested');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('backends')]
    public function testDeleting(Filesystem $filesystem): void
    {
        $path = $this->dir . '/doomed.json';
        file_put_contents($path, 'x');

        $gone = false;
        $filesystem->delete($path)->then(function (bool $ok) use (&$gone) {
            $gone = $ok;
        });

        $this->assertTrue($gone);
        $this->assertFileDoesNotExist($path);

        // Deleting what is already gone is success, not an error.
        $again = false;
        $filesystem->delete($path)->then(function (bool $ok) use (&$again) {
            $again = $ok;
        });
        $this->assertTrue($again);
    }

    public function testMoveReplacesAnExistingFileOnThisPlatform(): void
    {
        // The atomic save depends on it. PHP maps rename() to MoveFileEx with
        // MOVEFILE_REPLACE_EXISTING on Windows, so this holds there too — but
        // it is worth failing loudly if a platform ever disagrees.
        $from = $this->dir . '/new.json';
        $to = $this->dir . '/live.json';
        file_put_contents($from, 'new');
        file_put_contents($to, 'old');

        $this->assertTrue(Filesystem::move($from, $to));
        $this->assertSame('new', file_get_contents($to));
        $this->assertFileDoesNotExist($from);
    }

    public function testADurableWriteLandsCompleteOnDisk(): void
    {
        $path = $this->dir . '/durable.json';

        $this->assertTrue(Filesystem::writeDurably($path, str_repeat('a', 100000)));
        $this->assertSame(100000, filesize($path));
    }

    public function testADurableWriteToAnImpossiblePathFailsRatherThanThrowing(): void
    {
        $this->assertFalse(Filesystem::writeDurably($this->dir . '/nope/nope.json', 'x'));
    }

    public function testTheBlockingReaderIsUsedForBootAndCopesWithMissingFiles(): void
    {
        $path = $this->dir . '/boot.json';
        file_put_contents($path, 'contents');

        $this->assertSame('contents', Filesystem::readBlocking($path));
        $this->assertNull(Filesystem::readBlocking($this->dir . '/not-here.json'));
    }

    public function testAnAsyncBackendResolvesOnlyWhenTheWriteCompletes(): void
    {
        $adapter = new DeferredAdapter();
        $filesystem = Filesystem::with($adapter, 'deferred');
        $path = $this->dir . '/deferred.json';

        $resolved = false;
        $filesystem->write($path, 'later')->then(function () use (&$resolved) {
            $resolved = true;
        });

        $this->assertFalse($resolved, 'the promise must not resolve before the backend has finished');
        $this->assertSame(1, $adapter->pending());

        $adapter->settle();

        $this->assertTrue($resolved);
        $this->assertSame('later', file_get_contents($path));
    }
}
