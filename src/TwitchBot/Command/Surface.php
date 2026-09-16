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
 * Which chat an action was invoked from.
 *
 * Actions are written once and registered into both clients, so the handful of
 * places that genuinely must differ — formatting, and whether an answer is safe
 * to say out loud — ask the surface rather than being written twice.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
enum Surface: string
{
    case Discord = 'discord';
    case Twitch = 'twitch';

    /** Longest message body the surface accepts. */
    public function limit(): int
    {
        return match ($this) {
            self::Discord => 2000,
            self::Twitch => 500,
        };
    }

    /** Whether the surface renders Markdown; Twitch chat is flat text. */
    public function supportsMarkdown(): bool
    {
        return $this === self::Discord;
    }

    public function label(): string
    {
        return match ($this) {
            self::Discord => 'Discord',
            self::Twitch => 'Twitch',
        };
    }
}
