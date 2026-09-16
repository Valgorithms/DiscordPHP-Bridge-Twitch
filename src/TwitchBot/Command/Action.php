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

namespace TwitchBot\Command;

use React\Promise\PromiseInterface;

/**
 * One command, declared once and registered into both chat clients.
 *
 * The handler receives a {@see Context} and {@see Arguments} and returns the
 * reply — a string, `null` for "say nothing", or a promise of either. It is
 * never handed a `Message` or a `ChatMessage`, which is what keeps a single
 * definition serviceable from two platforms that agree on almost nothing.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Action
{
    /** @var \Closure(Context, Arguments): (string|null|PromiseInterface) */
    private readonly \Closure $handler;

    /**
     * @param list<string>                                             $aliases
     * @param callable(Context, Arguments): (string|null|PromiseInterface) $handler
     * @param Surface|null                                             $only     Restrict to one surface; `null` for both.
     * @param bool                                                     $sensitive Whether the reply may contain a secret.
     * @param Slash|null                                               $slash    Opt in to a Discord slash command.
     */
    public function __construct(
        public readonly string $name,
        callable $handler,
        public readonly string $description = '',
        public readonly string $usage = '',
        public readonly Access $access = Access::Everyone,
        public readonly array $aliases = [],
        public readonly int $cooldown = 0,
        public readonly ?Surface $only = null,
        public readonly bool $sensitive = false,
        public readonly string $group = 'general',
        public readonly ?Slash $slash = null,
    ) {
        $this->handler = \Closure::fromCallable($handler);

        if ($slash !== null && $only === Surface::Twitch) {
            throw new \LogicException("Action {$name} is Twitch-only but declares a slash command.");
        }
    }

    /** Whether this action is offered on `$surface` at all. */
    public function availableOn(Surface $surface): bool
    {
        return $this->only === null || $this->only === $surface;
    }

    /**
     * Runs the handler.
     *
     * Permission is *not* checked here — the adapters do that, because each one
     * has to report a refusal in its own idiom, and because Twitch's
     * {@see \Twitch\Chat\CommandClient} wants to make that decision itself.
     *
     * @return string|null|PromiseInterface
     */
    public function run(Context $context, Arguments $arguments): mixed
    {
        return ($this->handler)($context, $arguments);
    }

    /** A one-line help entry, e.g. `!title <text> — Set the stream title`. */
    public function help(string $prefix = '!'): string
    {
        $signature = $prefix . $this->name . ($this->usage !== '' ? ' ' . $this->usage : '');

        return $this->description === ''
            ? $signature
            : $signature . ' — ' . $this->description;
    }
}
