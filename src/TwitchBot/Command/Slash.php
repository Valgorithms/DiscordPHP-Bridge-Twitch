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
 * How an {@see Action} presents itself as a Discord slash command.
 *
 * Slash commands need something prefix commands do not: a declared, typed
 * parameter list, so Discord can render a picker and reject bad input before
 * the bot ever sees it. Rather than describe every action twice, an action
 * opts in by attaching one of these, and {@see SlashAdapter} turns it into the
 * real thing — reusing the same handler, unchanged.
 *
 * Actions without a spec are still reachable by prefix in both chats; they
 * simply do not appear in Discord's command menu.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Slash
{
    /**
     * @param list<SlashOption>     $options     For a flat command.
     * @param list<SlashSubcommand> $subcommands For one with sub-commands.
     * @param bool                  $ephemeral   Whether the reply is shown only to the invoker.
     */
    public function __construct(
        public readonly array $options = [],
        public readonly array $subcommands = [],
        public readonly bool $ephemeral = false,
    ) {
        if ($options !== [] && $subcommands !== []) {
            // Discord rejects a command that mixes them, and it would be
            // ambiguous here too: is the first token a sub-command or a value?
            throw new \LogicException('A slash command has options or sub-commands, not both.');
        }
    }

    public function hasSubcommands(): bool
    {
        return $this->subcommands !== [];
    }
}
