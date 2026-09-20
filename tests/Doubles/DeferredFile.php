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

namespace TwitchBot\Tests\Doubles;

use React\Filesystem\Node\FileInterface;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * The file node {@see DeferredAdapter} hands out: real I/O, deferred
 * completion.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class DeferredFile implements FileInterface
{
    public function __construct(
        private readonly DeferredAdapter $adapter,
        private readonly string $fullPath,
    ) {
    }

    public function getContents(int $offset = 0, ?int $maxlen = null): PromiseInterface
    {
        return $this->adapter->defer('read', $this->fullPath, fn (): string => (string) @file_get_contents($this->fullPath));
    }

    public function putContents(string $contents, int $flags = 0): PromiseInterface
    {
        return $this->adapter->defer(
            'write',
            $this->fullPath,
            // Suppressed the way a real adapter is: a failed write rejects the
            // promise, it does not warn.
            fn (): int|false => @file_put_contents($this->fullPath, $contents, $flags),
        );
    }

    public function unlink(): PromiseInterface
    {
        return $this->adapter->defer('unlink', $this->fullPath, fn (): bool => @unlink($this->fullPath));
    }

    public function stat(): PromiseInterface
    {
        return resolve(null);
    }

    public function path(): string
    {
        return \dirname($this->fullPath) . DIRECTORY_SEPARATOR;
    }

    public function name(): string
    {
        return basename($this->fullPath);
    }
}
