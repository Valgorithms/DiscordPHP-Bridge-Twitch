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

namespace TwitchBot\Support;

use Discord\Parts\Channel\Message;
use TwitchBot\Command\Access;
use TwitchBot\Command\Context;

/**
 * Works out which rung of {@see Access} a Discord message's author stands on.
 *
 * Channel-aware on purpose: `getPermissions($channel)` resolves the channel's
 * overwrites, so someone denied Manage Messages in one channel is not treated
 * as a moderator there merely because a role grants it server-wide.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Permissions
{
    /**
     * @param string|null $ownerId The configured bot operator's Discord user id.
     */
    public static function accessFor(Message $message, ?string $ownerId): Access
    {
        $userId = (string) ($message->author->id ?? '');
        $isOwner = $ownerId !== null && $ownerId !== '' && $userId === $ownerId;

        // A DM has no roles to read; the operator is still the operator there,
        // and nobody else gets anything above Everyone.
        $guild = $message->guild ?? null;
        if ($guild === null) {
            return $isOwner ? Access::Owner : Access::Everyone;
        }

        $isGuildOwner = $userId !== '' && (string) $guild->owner_id === $userId;

        $isAdmin = false;
        $isModerator = false;

        $member = $message->member ?? null;
        if ($member !== null) {
            $perms = $member->getPermissions($message->channel ?? null);

            if ($perms !== null) {
                $isAdmin = (bool) ($perms->administrator ?? false) || (bool) ($perms->manage_guild ?? false);
                $isModerator = $isAdmin || (bool) ($perms->manage_messages ?? false);
            }
        }

        return Context::discordAccess($isGuildOwner, $isAdmin, $isModerator, $isOwner);
    }
}
