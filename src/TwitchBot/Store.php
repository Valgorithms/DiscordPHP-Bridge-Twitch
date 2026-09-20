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

namespace TwitchBot;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

use TwitchBot\Support\Filesystem;

/**
 * JSON-file backed persistence for the bridge configuration — which Discord
 * channel is linked to which Twitch channel, per guild.
 *
 * ## Surviving a restart
 *
 * This file is the only thing that remembers a server's bridges, so losing it
 * means every admin has to run ``relay link`` again. Four things protect it:
 *
 *  - **Writes are atomic.** Content goes to a temp file, is flushed to the
 *    disk, and is then renamed over the target, so a crash mid-write cannot
 *    leave a half-written file where the configuration used to be.
 *  - **The previous good copy is kept** beside it as `.bak` after every write.
 *  - **A damaged file is never silently replaced.** If the JSON does not
 *    parse, the backup is tried; if that fails too, the file is preserved
 *    under a `.corrupt-<timestamp>` name rather than being overwritten by the
 *    next ``relay link``.
 *  - **Entries of the wrong shape are dropped, not loaded.** A hand-edited
 *    file cannot take the bridge down with a `TypeError` three layers away.
 *
 * Anything noticed while loading is recorded in {@see warnings()}, which the
 * bot logs — and tells its owner about — at startup.
 *
 * ## Reading and writing
 *
 * The load is blocking, once, in the constructor: it happens before `run()`,
 * when there is no loop to block, and starting a bridge before it knows what
 * it bridges would be worse than the microseconds it costs.
 *
 * Saves are the opposite. They happen while the loop is running — someone has
 * just used ``relay link`` — so they go through {@see Filesystem}, which
 * performs them off the loop where the platform allows it. A mutator updates
 * memory and returns immediately; the write is queued behind whatever is
 * already in flight, and several changes in a row collapse into one write
 * rather than queueing one each. {@see saved()} resolves when the disk has
 * caught up, which is what the tests wait on.
 *
 * Every mutator hands back a fresh {@see Links}, which is what callers act on;
 * the store owns persistence, {@see Links} owns routing.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Store
{
    /** Where the last known-good copy is kept. */
    public const BACKUP_SUFFIX = '.bak';

    /** @var array{links?: array<string, array<string, string>>} */
    private array $data;

    private readonly string $path;

    /** @var list<string> */
    private array $warnings = [];

    private readonly Filesystem $filesystem;

    /** The write in flight, if any. */
    private ?PromiseInterface $writing = null;

    /** Whether something changed while that write was in flight. */
    private bool $dirty = false;

    public function __construct(string $path, ?Filesystem $filesystem = null)
    {
        $this->path = $path;
        $this->filesystem = $filesystem ?? Filesystem::create();
        $this->data = $this->load();

        foreach (glob($path . '.*.tmp') ?: [] as $stale) {
            @unlink($stale);
        }
    }

    /**
     * Anything that went wrong while reading the file, in the order it was
     * found. Empty on a normal start.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** The filesystem it writes through, for the startup line. */
    public function filesystem(): Filesystem
    {
        return $this->filesystem;
    }

    /** Where this store keeps its state, for logging and for the startup check. */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Reads the configuration, falling back to the backup and refusing to
     * discard a file it cannot understand.
     *
     * @return array{links?: array<string, array<string, string>>}
     */
    private function load(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $data = self::decode($this->path);

        if ($data !== null) {
            return $this->sanitize($data);
        }

        $backup = self::decode($this->path . self::BACKUP_SUFFIX);

        if ($backup !== null) {
            $this->warnings[] = sprintf(
                '%s could not be read; recovered the previous copy from %s.',
                basename($this->path),
                basename($this->path) . self::BACKUP_SUFFIX,
            );

            return $this->sanitize($backup);
        }

        // Nothing usable. Move the unreadable file out of the way rather than
        // starting empty and letting the next save overwrite it for good.
        $kept = $this->path . '.corrupt-' . date('Ymd-His');

        $this->warnings[] = @rename($this->path, $kept)
            ? sprintf('%s could not be read and no backup was usable; it has been kept as %s and the bridge started with no configuration.', basename($this->path), basename($kept))
            : sprintf('%s could not be read and could not be moved aside; refusing to overwrite it, so nothing will be saved.', basename($this->path));

        return [];
    }

    /**
     * Drops anything that is not a guild of channel-to-login strings.
     *
     * @param  array<string, mixed> $data
     * @return array{links?: array<string, array<string, string>>}
     */
    private function sanitize(array $data): array
    {
        $clean = [];

        foreach ((array) ($data['links'] ?? []) as $guildId => $channels) {
            if (! is_array($channels)) {
                $this->warnings[] = sprintf('Ignored the entry for guild %s: it is not a list of channels.', (string) $guildId);

                continue;
            }

            foreach ($channels as $channelId => $login) {
                if (! is_scalar($login) || (string) $login === '') {
                    $this->warnings[] = sprintf('Ignored the bridge for channel %s in guild %s: its Twitch channel is not usable.', (string) $channelId, (string) $guildId);

                    continue;
                }

                $clean['links'][(string) $guildId][(string) $channelId] = strtolower((string) $login);
            }
        }

        return $clean;
    }

    /**
     * Reads and decodes one file, or `null` when it is missing, unreadable, or
     * not a JSON object.
     *
     * @return array<string, mixed>|null
     */
    private static function decode(string $path): ?array
    {

        $contents = Filesystem::readBlocking($path);

        if ($contents === null) {
            return null;
        }

        // An empty file is a legitimate "nothing configured yet" — a store
        // that has been created but never written to.
        if (trim($contents) === '') {
            return [];
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** The current routing table. */
    public function links(): Links
    {
        /** @var array<string, array<string, string>> $links */
        $links = $this->data['links'] ?? [];

        return new Links($links);
    }

    /**
     * Bridges a Discord channel to a Twitch channel.
     *
     * A Discord channel can only point at one Twitch channel, so setting it
     * again replaces the previous target rather than accumulating.
     */
    public function link(int|string $guildId, int|string $discordChannelId, string $twitchLogin): Links
    {
        $this->data['links'][(string) $guildId][(string) $discordChannelId] = strtolower($twitchLogin);
        $this->save();

        return $this->links();
    }

    /** Removes one Discord channel's bridge. No-op when it was not bridged. */
    public function unlink(int|string $guildId, int|string $discordChannelId): Links
    {
        $guild = (string) $guildId;
        $channel = (string) $discordChannelId;

        if (isset($this->data['links'][$guild][$channel])) {
            unset($this->data['links'][$guild][$channel]);

            if (($this->data['links'][$guild] ?? []) === []) {
                unset($this->data['links'][$guild]);
            }

            $this->save();
        }

        return $this->links();
    }

    /** Drops every bridge a guild has configured. */
    public function forgetGuild(int|string $guildId): Links
    {
        if (isset($this->data['links'][(string) $guildId])) {
            unset($this->data['links'][(string) $guildId]);
            $this->save();
        }

        return $this->links();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Resolves when everything changed so far has reached the disk.
     *
     * Nothing has to await this — a save is fire-and-forget by design — but a
     * test does, and so does a shutdown that wants to be sure. It follows the
     * queue to the end: a change made while a write was in flight schedules
     * another one, and this resolves after that too.
     *
     * @return PromiseInterface<bool>
     */
    public function saved(): PromiseInterface
    {
        if ($this->writing === null) {
            return resolve(true);
        }

        return $this->writing->then(fn (): PromiseInterface => $this->saved());
    }

    /**
     * Writes now, blocking, and returns whether it worked.
     *
     * For shutdown: the loop is about to stop, so a queued write would never
     * run. Everything else should use the queue.
     */
    public function flush(): bool
    {
        if (! $this->dirty && $this->writing === null) {
            return true;
        }

        $this->dirty = false;
        $this->writing = null;

        $json = $this->encode();

        return $json !== null && $this->writeAtomically($json, Filesystem::blocking());
    }

    /**
     * Queues a save, coalescing anything that arrives while one is running.
     *
     * Two ``relay link`` calls in the same second produce one write, and the
     * second one still ends up on disk: the flag is checked when the first
     * completes.
     */
    private function save(): void
    {
        if ($this->writing !== null) {
            $this->dirty = true;

            return;
        }

        // The pending promise has to exist *before* the write starts. With a
        // synchronous backend the completion callback runs inside then(), so
        // assigning its return value afterwards would put a finished promise
        // back over the null the callback had just written — and every later
        // save would think one was still in flight and never run.
        $deferred = new Deferred();
        $this->writing = $deferred->promise();

        $this->write()->then(function (bool $ok) use ($deferred): void {
            $this->writing = null;

            if ($this->dirty) {
                $this->dirty = false;
                $this->save();
            }

            $deferred->resolve($ok);
        });
    }

    /**
     * Encode, write a pid-suffixed sibling temp file, rename it over the
     * target, then write the backup.
     *
     * Bails without touching the live file if the data cannot be encoded or
     * the temp write fails, so a bad value never truncates state. The backup
     * is written *after* the save, not by copying the file about to be
     * replaced: a backup that is one write behind would recover a
     * configuration missing whatever was just added, which is exactly the
     * change somebody would notice.
     *
     * @return PromiseInterface<bool>
     */
    private function write(): PromiseInterface
    {
        $json = $this->encode();

        if ($json === null) {
            return resolve(false);
        }

        Filesystem::ensureDirectory(\dirname($this->path));

        $tmp = $this->path . '.' . getmypid() . '.tmp';

        return $this->filesystem->write($tmp, $json)->then(function (bool $written) use ($tmp, $json): PromiseInterface {
            if (! $written || ! Filesystem::move($tmp, $this->path)) {
                return $this->filesystem->delete($tmp)->then(static fn (): bool => false);
            }

            $backupTmp = $this->path . '.' . getmypid() . '.bak.tmp';

            return $this->filesystem->write($backupTmp, $json)->then(function (bool $ok) use ($backupTmp): bool {
                if ($ok) {
                    Filesystem::move($backupTmp, $this->path . self::BACKUP_SUFFIX);
                }

                return true;
            });
        });
    }

    /**
     * The same dance, without promises, for {@see flush()}.
     *
     * A shutdown has no loop left to resolve a promise on, so this one path
     * stays synchronous on purpose.
     */
    private function writeAtomically(string $json, Filesystem $filesystem): bool
    {
        Filesystem::ensureDirectory(\dirname($this->path));

        $tmp = $this->path . '.' . getmypid() . '.tmp';

        if (! Filesystem::writeDurably($tmp, $json) || ! Filesystem::move($tmp, $this->path)) {
            @unlink($tmp);

            return false;
        }

        $backupTmp = $this->path . '.' . getmypid() . '.bak.tmp';

        if (Filesystem::writeDurably($backupTmp, $json)) {
            Filesystem::move($backupTmp, $this->path . self::BACKUP_SUFFIX);
        } else {
            @unlink($backupTmp);
        }

        return true;
    }

    /** The configuration as JSON, or `null` when it cannot be encoded. */
    private function encode(): ?string
    {
        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? null : $json;
    }
}
