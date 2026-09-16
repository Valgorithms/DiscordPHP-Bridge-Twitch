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
    /** Administrator. */
    public const ADMINISTRATOR = 1 << 3;

    /** Manage Server. */
    public const MANAGE_GUILD = 1 << 5;

    /** Manage Messages — the closest Discord has to a chat moderator. */
    public const MANAGE_MESSAGES = 1 << 13;

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

    /**
     * The same ladder, for a slash command.
     *
     * An interaction carries its own resolved permissions rather than a cached
     * {@see \Discord\Parts\User\Member}, and it is the better source: Discord
     * has already folded channel overwrites into the bitfield, so it is correct
     * even when the member cache is cold or the bot lacks the members intent.
     */
    public static function accessForInteraction(object $interaction, ?string $ownerId): Access
    {
        $userId = (string) ($interaction->user->id ?? '');
        $isOwner = $ownerId !== null && $ownerId !== '' && $userId === $ownerId;

        $guild = $interaction->guild ?? null;

        if ($guild === null) {
            return $isOwner ? Access::Owner : Access::Everyone;
        }

        $isGuildOwner = $userId !== '' && (string) $guild->owner_id === $userId;
        $bits = self::bitsFromInteraction($interaction);

        return Context::discordAccess(
            $isGuildOwner,
            self::bitsGrant($bits, self::ADMINISTRATOR | self::MANAGE_GUILD),
            self::bitsGrant($bits, self::ADMINISTRATOR | self::MANAGE_GUILD | self::MANAGE_MESSAGES),
            $isOwner,
        );
    }

    /**
     * The `member.permissions` decimal string off an interaction payload, or
     * `null` when unreachable (a DM, or a shape we do not know).
     *
     * Reads the *raw* attributes first: DiscordPHP's `->member` getter runs a
     * transform that does not preserve `permissions`, so going through it alone
     * silently loses the value and every check quietly fails closed.
     */
    public static function bitsFromInteraction(object $interaction): ?string
    {
        $candidates = [];

        if (method_exists($interaction, 'getRawAttributes')) {
            $candidates[] = $interaction->getRawAttributes()['member'] ?? null;
        }
        $candidates[] = $interaction->member ?? null;

        foreach ($candidates as $member) {
            $bits = self::rawPermValue($member);

            if ($bits !== null) {
                return $bits;
            }
        }

        return null;
    }

    /** Whether a permissions bitfield carries any of `$mask`. */
    public static function bitsGrant(int|string|null $bits, int $mask): bool
    {
        if ($bits === null || $bits === '') {
            return false;
        }

        return ((is_string($bits) ? (int) $bits : $bits) & $mask) !== 0;
    }

    /**
     * Pulls `permissions` out of whatever shape a `member` attribute took — a
     * gateway `stdClass`, an array, or a Part (raw attributes, not the getter)
     * — normalised to a decimal string.
     */
    private static function rawPermValue(mixed $member): ?string
    {
        if (is_array($member)) {
            $perms = $member['permissions'] ?? null;
        } elseif (is_object($member)) {
            $perms = null;

            if (method_exists($member, 'getRawAttributes')) {
                $perms = $member->getRawAttributes()['permissions'] ?? null;
            }

            $perms ??= $member->permissions ?? null;
        } else {
            return null;
        }

        return $perms === null || is_object($perms) ? null : (string) $perms;
    }
}
