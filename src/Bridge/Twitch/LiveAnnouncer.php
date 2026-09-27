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

use Bridge\Support\JsonFile;
use Bridge\Support\MessageText;
use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;

use function React\Promise\all;
use function React\Promise\resolve;

/**
 * Says in each bridged Discord channel when its Twitch channel goes live, and
 * when the stream ends.
 *
 * It checks every minute, with one Helix call per hundred bridged channels.
 * EventSub would be quicker, but it limits subscriptions for channels that have
 * not authorized the app, and a bridge follows other people's channels too.
 *
 * - **Restarts:** what was announced is kept on disk, so a restart in the
 *   middle of a stream does not announce it again.
 * - **Blips:** an end is announced only after OFFLINE_AFTER checks in a row
 *   without the stream. An encoder that drops for a moment has not ended the
 *   stream, and a stream back within that window is the same one, whatever id
 *   Twitch gives it.
 * - **Downtime:** a stream that ended while the bot was down for longer than
 *   that is let go quietly. "Stream ended" an hour late is noise.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class LiveAnnouncer
{
    /** Seconds between checks. */
    public const INTERVAL = 60.0;

    /** Checks in a row without the stream before it counts as ended. */
    public const OFFLINE_AFTER = 2;

    /** Helix takes this many logins in one request. */
    private const PER_REQUEST = 100;

    /** @var array<string, array{id: string, login: string, name: string, title: string, game: string, started_at: int, misses: int}> The streams seen live, by login. */
    private array $live = [];

    /** When the last check finished, as a Unix time. */
    private ?float $checkedAt = null;

    private bool $checking = false;

    private ?TimerInterface $timer = null;

    /**
     * @param \Closure(): list<string>                                  $logins   Every bridged Twitch login.
     * @param \Closure(list<string>): PromiseInterface<list<array{id: string, login: string, name: string, title: string, game: string, started_at: int}>> $fetch
     *                                                                            The streams live now among those logins.
     * @param \Closure(string, string, array<string, mixed>): void       $announce Posts text to every Discord channel
     *                                                                            bridged to a login: the login, the
     *                                                                            markdown, and the stream it is about.
     * @param (\Closure(): float)|null                                  $clock    For tests.
     */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly LoggerInterface $logger,
        private readonly JsonFile $state,
        private readonly \Closure $logins,
        private readonly \Closure $fetch,
        private readonly \Closure $announce,
        private readonly ?\Closure $clock = null,
    ) {
        $saved = $state->load();
        $this->live = is_array($saved['live'] ?? null) ? $saved['live'] : [];
        $this->checkedAt = isset($saved['checked_at']) ? (float) $saved['checked_at'] : null;
    }

    /** Checks now, then every INTERVAL seconds. */
    public function start(): void
    {
        if ($this->timer !== null) {
            return;
        }

        $this->check();
        $this->timer = $this->loop->addPeriodicTimer(self::INTERVAL, fn () => $this->check());
    }

    /** Stops checking, and writes what it knows to disk. */
    public function stop(): void
    {
        if ($this->timer !== null) {
            $this->loop->cancelTimer($this->timer);
            $this->timer = null;
        }

        $this->state->flush();
    }

    /**
     * One check: who is live now, against who was.
     *
     * @return PromiseInterface<null> Never rejects. A failed check says nothing
     *                                about who is live, so it changes nothing,
     *                                and the next one tries again.
     */
    public function check(): PromiseInterface
    {
        if ($this->checking) {
            return resolve(null);
        }

        $logins = array_values(array_unique(array_map('strtolower', ($this->logins)())));
        $now = $this->now();

        // The bot was down for longer than an end takes to confirm, so
        // whatever ended meanwhile ended unseen.
        $stale = $this->checkedAt !== null && $now - $this->checkedAt > self::INTERVAL * (self::OFFLINE_AFTER + 1);

        $this->checking = true;
        $requests = array_map(fn (array $chunk): PromiseInterface => ($this->fetch)($chunk), array_chunk($logins, self::PER_REQUEST));

        return all($requests)->then(
            function (array $chunks) use ($logins, $now, $stale): void {
                $current = [];
                foreach ($chunks as $streams) {
                    foreach ($streams as $stream) {
                        $current[strtolower($stream['login'])] = $stream;
                    }
                }

                $this->reconcile($logins, $current, $now, $stale);
                $this->checkedAt = $now;
                $this->state->save(['checked_at' => $now, 'live' => $this->live]);
            },
            fn (\Throwable $e) => $this->logger->warning('[twitch] could not check who is live: ' . $e->getMessage()),
        )->then(
            null,
            fn (\Throwable $e) => $this->logger->error('[twitch] announcing a stream failed: ' . $e->getMessage()),
        )->finally(function (): void {
            $this->checking = false;
        });
    }

    /** @return array<string, array<string, mixed>> The streams seen live, by login. */
    public function live(): array
    {
        return $this->live;
    }

    /**
     * @param list<string>                        $logins  Every bridged login.
     * @param array<string, array<string, mixed>> $current The streams live now, by login.
     */
    private function reconcile(array $logins, array $current, float $now, bool $stale): void
    {
        foreach ($current as $login => $stream) {
            if (! in_array($login, $logins, true)) {
                continue;
            }

            $known = $this->live[$login] ?? null;
            $this->live[$login] = $stream + ['misses' => 0];

            // The same stream, or one back within the grace window. It keeps
            // its start, so its length reads right when it ends.
            if ($known !== null && ($known['id'] === $stream['id'] || $known['misses'] > 0)) {
                $this->live[$login]['started_at'] = min((int) $known['started_at'], (int) $stream['started_at']);
                continue;
            }

            ($this->announce)($login, self::liveText($stream), $stream);
        }

        foreach ($this->live as $login => $stream) {
            if (isset($current[$login])) {
                continue;
            }

            // Unbridged since, or ended while the bot was down: nothing to say.
            if (! in_array($login, $logins, true) || $stale) {
                unset($this->live[$login]);
                continue;
            }

            if (++$this->live[$login]['misses'] < self::OFFLINE_AFTER) {
                continue;
            }

            unset($this->live[$login]);
            ($this->announce)($login, self::endedText($stream, $now), $stream);
        }
    }

    /** @param array<string, mixed> $stream */
    public static function liveText(array $stream): string
    {
        $lines = ['🔴 **Live now:** ' . self::clean((string) $stream['title'])];

        if ((string) $stream['game'] !== '') {
            $lines[] = 'Streaming ' . self::clean((string) $stream['game']);
        }

        $lines[] = 'https://www.twitch.tv/' . rawurlencode((string) $stream['login']);

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $stream */
    public static function endedText(array $stream, float $now): string
    {
        $minutes = max(1, (int) round(($now - (int) $stream['started_at']) / 60));
        $duration = $minutes >= 60
            ? sprintf('%dh %dm', intdiv($minutes, 60), $minutes % 60)
            : sprintf('%dm', $minutes);

        return sprintf('⚫ **Stream ended** after %s.', $duration);
    }

    /** A title or game name, which came from Twitch: one line, markdown shown as typed. */
    private static function clean(string $text): string
    {
        return MessageText::escapeMarkdown(MessageText::truncate(MessageText::collapseWhitespace($text), 200));
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }
}
