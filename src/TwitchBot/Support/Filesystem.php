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

use React\Filesystem\AdapterInterface;
use React\Filesystem\Eio;
use React\Filesystem\Factory;
use React\Filesystem\Uv;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Every disk access the bridge makes while the event loop is running, in one
 * place, behind promises.
 *
 * ## Why this exists
 *
 * A blocking `file_put_contents()` stops the loop: for as long as it runs, no
 * Discord heartbeat is sent, no Telegram update is read, nothing is relayed.
 * Small as those pauses are here, they are real — this bot's own saves measure
 * around 2-3 ms on a Windows host — and they are exactly the kind of thing
 * that gets worse on the machine you did not test on.
 *
 * So writes go through [react/filesystem](https://github.com/reactphp/filesystem),
 * which performs them off the loop when a native async backend is available.
 *
 * ## What actually happens on each platform
 *
 * `react/filesystem` picks a backend at runtime, and this matters more than it
 * looks:
 *
 *  - **ext-eio** (POSIX only) — genuinely asynchronous.
 *  - **ext-uv** (POSIX *and* Windows; `php_uv.dll` is published on PECL) —
 *    genuinely asynchronous, provided the process is running ReactPHP's
 *    `ExtUvLoop`, which it does automatically when the extension is loaded.
 *  - **neither** — `react/filesystem` falls back to an adapter that calls
 *    `file_get_contents()` and `file_put_contents()` and wraps the result in
 *    an already-resolved promise. It has the asynchronous *shape* and none of
 *    the asynchronous behaviour.
 *
 * That last case is the common one on Windows, so this class does not pretend
 * otherwise. When no async backend is present it does the write itself,
 * blocking — and since it is going to block anyway, it blocks *properly*:
 * `fflush()` and `fsync()`, so a machine that loses power cannot leave a
 * zero-length file where the configuration was. `putContents()` cannot express
 * that, and trading durability for a promise that resolves just as late would
 * be a bad deal.
 *
 * {@see adapter()} reports which of the three is in use, and the bot says so
 * at startup, so nobody has to guess whether they are getting real async I/O.
 *
 * ## What is not routed through here
 *
 * `rename()`, `mkdir()` and `is_file()` are metadata operations measured in
 * microseconds, and `react/filesystem` offers no asynchronous equivalent of
 * the first two at all. They stay as direct calls, deliberately.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Filesystem
{
    public const ADAPTER_EIO = 'ext-eio';

    public const ADAPTER_UV = 'ext-uv';

    public const ADAPTER_BLOCKING = 'blocking';

    private function __construct(
        private readonly ?AdapterInterface $adapter,
        private readonly string $name,
    ) {
    }

    /**
     * Picks the best backend this machine offers.
     *
     * `react/filesystem`'s own factory is asked first, and its answer is only
     * kept when it is one of the two native backends: its fallback adapter is
     * synchronous, and this class has a better synchronous path of its own.
     */
    public static function create(): self
    {
        $adapter = Factory::create();

        if ($adapter instanceof Eio\Adapter) {
            return new self($adapter, self::ADAPTER_EIO);
        }

        if ($adapter instanceof Uv\Adapter) {
            return new self($adapter, self::ADAPTER_UV);
        }

        return self::blocking();
    }

    /** Durable, blocking I/O — what runs when no async backend is installed. */
    public static function blocking(): self
    {
        return new self(null, self::ADAPTER_BLOCKING);
    }

    /**
     * A specific adapter, for tests.
     *
     * `React\Filesystem\Fallback\Adapter` is a real `AdapterInterface` that
     * resolves immediately, which makes it an honest stand-in for the async
     * path on a machine that has no async extension.
     */
    public static function with(AdapterInterface $adapter, string $name = 'custom'): self
    {
        return new self($adapter, $name);
    }

    /** Which backend is in use: `ext-eio`, `ext-uv`, or `blocking`. */
    public function adapter(): string
    {
        return $this->name;
    }

    /** Whether disk access actually happens off the event loop. */
    public function isAsynchronous(): bool
    {
        return $this->adapter !== null;
    }

    /** One line for the startup log, including what to do about it. */
    public function describe(): string
    {
        if ($this->isAsynchronous()) {
            return sprintf('filesystem: asynchronous via %s', $this->name);
        }

        return 'filesystem: synchronous (no ext-uv or ext-eio) — writes are durable but briefly block the loop; '
            . 'install php-uv for non-blocking disk I/O';
    }

    /**
     * The contents of a file, or `null` when it is not there or cannot be
     * read.
     *
     * The existence check is deliberate rather than left to the adapter: the
     * fallback adapter hands a missing path straight to `file_get_contents()`,
     * which emits a warning and resolves with `false` instead of rejecting.
     *
     * @return PromiseInterface<string|null>
     */
    public function read(string $path): PromiseInterface
    {
        if (! is_file($path)) {
            return resolve(null);
        }

        if ($this->adapter === null) {
            return resolve(self::readBlocking($path));
        }

        return $this->adapter->file($path)->getContents()->then(
            static fn (mixed $contents): ?string => is_string($contents) ? $contents : null,
            static fn (): ?string => null,
        );
    }

    /**
     * Reads a file without involving the loop at all.
     *
     * For the one moment when that is the right thing to do: loading
     * configuration during construction, before `run()` has been called and
     * before there is a loop to block.
     */
    public static function readBlocking(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /**
     * Writes a file, replacing whatever was there.
     *
     * @return PromiseInterface<bool> whether the contents reached the disk
     */
    public function write(string $path, string $contents): PromiseInterface
    {
        self::ensureDirectory(\dirname($path));

        if ($this->adapter === null) {
            return resolve(self::writeDurably($path, $contents));
        }

        return $this->adapter->file($path)->putContents($contents)->then(
            static fn (mixed $written): bool => $written !== false,
            static fn (): bool => false,
        );
    }

    /**
     * Deletes a file. Resolves `true` when it is gone, including when it was
     * never there.
     *
     * @return PromiseInterface<bool>
     */
    public function delete(string $path): PromiseInterface
    {
        if (! is_file($path)) {
            return resolve(true);
        }

        if ($this->adapter === null) {
            return resolve(@unlink($path));
        }

        return $this->adapter->file($path)->unlink()->then(
            static fn (): bool => true,
            static fn (): bool => false,
        );
    }

    /**
     * Renames a file over another.
     *
     * Stays a direct call on every backend: `react/filesystem` has no
     * asynchronous rename, and this is a metadata operation that does not move
     * bytes. On Windows PHP maps it to `MoveFileEx` with
     * `MOVEFILE_REPLACE_EXISTING`, so replacing an existing target works there
     * as it does on POSIX — which is what makes the atomic-save dance
     * portable.
     */
    public static function move(string $from, string $to): bool
    {
        return @rename($from, $to);
    }

    /** Creates a directory and its parents if they are not there yet. */
    public static function ensureDirectory(string $path): bool
    {
        return is_dir($path) || @mkdir($path, 0o777, true) || is_dir($path);
    }

    /**
     * Writes a file and waits for the disk to acknowledge it.
     *
     * The `fsync` is the point: without it a rename can land while the content
     * is still in the page cache, so a machine that loses power comes back to
     * a zero-length file where the configuration used to be.
     */
    public static function writeDurably(string $path, string $contents): bool
    {
        $handle = @fopen($path, 'wb');

        if ($handle === false) {
            return false;
        }

        $written = @fwrite($handle, $contents);
        $flushed = $written === strlen($contents) && @fflush($handle);

        // fsync() can fail on exotic filesystems; that is not a reason to
        // throw away a write that otherwise succeeded.
        if ($flushed && function_exists('fsync')) {
            @fsync($handle);
        }

        @fclose($handle);

        return $flushed;
    }
}
