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

use React\Filesystem\AdapterInterface;
use React\Filesystem\Node;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * A `react/filesystem` adapter that does the work but finishes only when the
 * test says so.
 *
 * `ext-uv` and `ext-eio` are not installed on a plain Windows host — and are
 * not installable in CI on a whim — so without this the asynchronous path
 * would never be executed by the suite, and every ordering bug in it would
 * wait for production to be found. (One already did: the write queue held the
 * promise returned by `then()`, which a synchronous backend resolves *during*
 * registration, so the queue never cleared.)
 *
 * Writes land on the real filesystem, so assertions about the file itself
 * still work; what is deferred is when the promise resolves.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class DeferredAdapter implements AdapterInterface
{
    /** @var list<Deferred> Pending operations, oldest first. */
    private array $pending = [];

    /** @var list<array{operation: string, path: string}> Everything asked of it. */
    private array $log = [];

    public function detect(string $path): PromiseInterface
    {
        return resolve($this->file($path));
    }

    public function directory(string $path): Node\DirectoryInterface
    {
        throw new \LogicException('The bridge never asks for a directory node.');
    }

    public function file(string $path): Node\FileInterface
    {
        return new DeferredFile($this, $path);
    }

    /**
     * Registers an operation and hands back the promise the caller will wait
     * on.
     *
     * @param  callable(): mixed $work
     * @return PromiseInterface<mixed>
     */
    public function defer(string $operation, string $path, callable $work): PromiseInterface
    {
        $this->log[] = ['operation' => $operation, 'path' => $path];

        $deferred = new Deferred();
        $this->pending[] = [$deferred, $work];

        return $deferred->promise();
    }

    /** How many operations are waiting. */
    public function pending(): int
    {
        return count($this->pending);
    }

    /**
     * Runs everything that is waiting, including anything those resolutions
     * queue in turn — which is how a coalesced follow-up write gets its turn.
     */
    public function settle(): void
    {
        while ($this->pending !== []) {
            [$deferred, $work] = array_shift($this->pending);
            $deferred->resolve($work());
        }
    }

    /**
     * Every operation asked of it, in order.
     *
     * @return list<array{operation: string, path: string}>
     */
    public function log(): array
    {
        return $this->log;
    }

    /** How many times one kind of operation was asked for. */
    public function countOf(string $operation): int
    {
        return count(array_filter($this->log, static fn (array $entry): bool => $entry['operation'] === $operation));
    }
}
