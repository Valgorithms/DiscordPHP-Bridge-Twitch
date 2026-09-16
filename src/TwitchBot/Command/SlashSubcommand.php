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
 * One sub-command of a slash command.
 *
 * Maps to the first positional token of the prefix form, which is what lets
 * `/relay link twitch:x` and `!relay link x` reach the same handler.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class SlashSubcommand
{
    /** @param list<SlashOption> $options */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $options = [],
    ) {
    }
}
