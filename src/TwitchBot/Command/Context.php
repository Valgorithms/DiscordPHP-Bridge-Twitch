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

use TwitchBot\Bot;

/**
 * Everything an action knows about the invocation it is servicing: who asked,
 * from where, what they are allowed to do, and — the interesting part — which
 * Twitch channel the action should act upon.
 *
 * That last one is the whole reason this object exists. On Twitch the target is
 * obvious: the channel the command was typed in. On Discord there is no such
 * thing, so the target is the Twitch channel bridged to that Discord channel,
 * which means `!title` does the same thing in both places without either
 * handler knowing how the other one resolved it.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Context
{
    /**
     * @param string      $invokerName      Display name, for addressing a reply.
     * @param string      $invokerId        Platform user id.
     * @param string|null $broadcasterId    Twitch user id this action acts on.
     * @param string|null $broadcasterLogin Its login, for messages.
     * @param bool        $isPublic         Whether the reply lands somewhere many people read.
     */
    public function __construct(
        public readonly Bot $bot,
        public readonly Surface $surface,
        public readonly Access $access,
        public readonly string $invokerName,
        public readonly string $invokerId,
        public readonly ?string $broadcasterId = null,
        public readonly ?string $broadcasterLogin = null,
        public readonly bool $isPublic = true,
        public readonly ?object $message = null,
    ) {
    }

    /** A copy pointed at a different Twitch channel. */
    public function withBroadcaster(?string $id, ?string $login): self
    {
        return new self(
            $this->bot,
            $this->surface,
            $this->access,
            $this->invokerName,
            $this->invokerId,
            $id,
            $login,
            $this->isPublic,
            $this->message,
        );
    }

    /**
     * The broadcaster id, or a thrown explanation.
     *
     * Actions that edit a channel call this rather than testing for null
     * themselves, so the "this Discord channel isn't bridged yet" message is
     * worded once instead of thirty times.
     *
     * @throws ActionError
     */
    public function requireBroadcaster(): string
    {
        if ($this->broadcasterId === null || $this->broadcasterId === '') {
            throw new ActionError($this->surface === Surface::Discord
                ? 'this channel is not linked to a Twitch channel yet — run `relay link <twitch-channel>` first, or pass `channel=<name>`.'
                : 'could not work out which Twitch channel this applies to.');
        }

        return $this->broadcasterId;
    }

    /** Whether the invoker is the bot operator. */
    public function isOwner(): bool
    {
        return $this->access === Access::Owner;
    }

    /**
     * Resolves a Twitch permission rung from a chat message's badges.
     *
     * Static and free of any Twitch part type so the ladder can be tested
     * directly; the adapter passes the three booleans it reads off the tags.
     */
    public static function twitchAccess(bool $isBroadcaster, bool $isMod, bool $isOwner): Access
    {
        return match (true) {
            $isOwner => Access::Owner,
            $isBroadcaster => Access::Broadcaster,
            $isMod => Access::Moderator,
            default => Access::Everyone,
        };
    }

    /**
     * Resolves a Discord permission rung.
     *
     * Guild owners and Administrators are treated as the broadcaster, because
     * on Discord that is the closest equivalent: they are the people whose
     * server it is. Manage Messages maps to moderator.
     */
    public static function discordAccess(bool $isGuildOwner, bool $isAdmin, bool $isModerator, bool $isOwner): Access
    {
        return match (true) {
            $isOwner => Access::Owner,
            $isGuildOwner || $isAdmin => Access::Broadcaster,
            $isModerator => Access::Moderator,
            default => Access::Everyone,
        };
    }
}
