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

use Discord\Parts\Interactions\Command\Option as DiscordOption;

/**
 * One typed parameter of a slash command.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class SlashOption
{
    public const STRING = DiscordOption::STRING;
    public const INTEGER = DiscordOption::INTEGER;
    public const BOOLEAN = DiscordOption::BOOLEAN;
    public const USER = DiscordOption::USER;
    public const CHANNEL = DiscordOption::CHANNEL;

    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly int $type = self::STRING,
        public readonly bool $required = false,
    ) {
    }

    /**
     * Renders a value from an interaction into the text a prefix handler would
     * have received.
     *
     * This is what lets one handler serve both surfaces. A CHANNEL option
     * arrives as a bare snowflake, but the prefix handler parses `<#id>`
     * because that is what someone types in Discord — so it is given `<#id>`,
     * and neither handler needs to know which surface it is on.
     */
    public function render(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return match ($this->type) {
            self::CHANNEL => '<#' . $value . '>',
            self::USER => '<@' . $value . '>',
            default => (string) $value,
        };
    }
}
