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

/**
 * The catalogue of every action the bot knows, and the single place either
 * adapter looks when it wires itself up.
 *
 * Names and aliases share one namespace, and a collision throws rather than
 * quietly overwriting: two actions answering to `!title` would otherwise mean
 * whichever registered last wins, with nothing in the log to say so.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class ActionRegistry
{
    /** @var array<string, Action> */
    private array $actions = [];

    /** @var array<string, string> alias => action name */
    private array $aliases = [];

    public function add(Action $action): self
    {
        $name = strtolower($action->name);

        if ($this->has($name)) {
            throw new \LogicException("Duplicate command name or alias: {$name}");
        }

        $this->actions[$name] = $action;

        foreach ($action->aliases as $alias) {
            $alias = strtolower($alias);

            if ($this->has($alias)) {
                throw new \LogicException("Duplicate command name or alias: {$alias}");
            }

            $this->aliases[$alias] = $name;
        }

        return $this;
    }

    /** Adds every action from a provider. */
    public function addAll(ActionProvider $provider): self
    {
        foreach ($provider->actions() as $action) {
            $this->add($action);
        }

        return $this;
    }

    public function has(string $name): bool
    {
        $name = strtolower($name);

        return isset($this->actions[$name]) || isset($this->aliases[$name]);
    }

    public function get(string $name): ?Action
    {
        $name = strtolower($name);

        return $this->actions[$name] ?? $this->actions[$this->aliases[$name] ?? ''] ?? null;
    }

    /** @return array<string, Action> */
    public function all(): array
    {
        return $this->actions;
    }

    /**
     * Everything offered on one surface.
     *
     * @return array<string, Action>
     */
    public function forSurface(Surface $surface): array
    {
        return array_filter($this->actions, static fn (Action $a): bool => $a->availableOn($surface));
    }

    /**
     * Actions grouped for a help listing, in registration order within each
     * group, and filtered to what `$access` may actually run — a viewer asking
     * for help should not be shown a list of things they will be refused.
     *
     * @return array<string, list<Action>>
     */
    public function grouped(Surface $surface, Access $access): array
    {
        $groups = [];

        foreach ($this->forSurface($surface) as $action) {
            if (! $access->satisfies($action->access)) {
                continue;
            }

            $groups[$action->group][] = $action;
        }

        return $groups;
    }

    public function count(): int
    {
        return count($this->actions);
    }
}
