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
 * One permission ladder for two very different permission systems.
 *
 * Twitch has badges (broadcaster, moderator, VIP, subscriber); Discord has a
 * role bitfield and a guild owner. An action declares the rung it needs, and
 * each surface's adapter is responsible for working out which rung the invoker
 * stands on — see {@see Context::$access}.
 *
 * The ladder is deliberately coarse. Anything finer would have to be explained
 * twice, once per platform, and an action's author would have to know both.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
enum Access: int
{
    /** Anyone in chat. */
    case Everyone = 0;

    /** Twitch moderators; Discord's Manage Messages or above. */
    case Moderator = 1;

    /**
     * The person whose channel it is: the Twitch broadcaster, or on Discord the
     * guild owner / an Administrator. This is the rung that may change what the
     * stream looks like — title, category, tags.
     */
    case Broadcaster = 2;

    /**
     * Whoever runs the bot process. Not a chat role at all: it is configured in
     * the environment, and it gates the things that can reach any endpoint or
     * read back a secret.
     */
    case Owner = 3;

    /** Whether standing on this rung is enough to run something needing `$required`. */
    public function satisfies(self $required): bool
    {
        return $this->value >= $required->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Everyone => 'everyone',
            self::Moderator => 'moderators',
            self::Broadcaster => 'the broadcaster',
            self::Owner => 'the bot owner',
        };
    }
}
