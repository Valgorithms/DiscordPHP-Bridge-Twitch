<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-Bridge-Twitch project.
 *
 * Copyright (c) 2026-present Valithor Obsidion <valithor@valgorithms.com>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Bridge\Twitch;

use Bridge\Bot;
use Bridge\Command\ChatDispatcher;
use Bridge\Command\ChatInvocation;
use Bridge\Command\Context;
use Twitch\Parts\ChatMessage;

/**
 * Runs the whole catalogue from Twitch chat.
 *
 * The *whole* catalogue, deliberately: this is where `!telegram send` typed in
 * a Twitch chat becomes a message in a Telegram group. One bot rather than two
 * is only worth anything if the commands cross over, so every connector's
 * actions are offered here, not just this one's.
 *
 * Everything but the Twitch-specific parts is the core's
 * {@see ChatDispatcher}, so access, cooldowns and cross-network targets behave
 * exactly as they do in every other chat. What only this class knows is where
 * Twitch keeps rank (badges, plus the configured owner) and the room's own id
 * (the `room-id` tag, the broadcaster's user id, so no lookup is needed).
 *
 * TwitchPHP's own `CommandClient` is not used. It matches a single word and
 * enforces its own cooldowns, so it would see `!twitch` and nothing after it —
 * and a second cooldown clock that disagrees with the one Discord uses.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class TwitchAdapter
{
    private readonly ChatDispatcher $dispatcher;

    public function __construct(
        private readonly TwitchConnector $connector,
        Bot $bot,
    ) {
        $this->dispatcher = new ChatDispatcher($bot, $connector);
    }

    /**
     * Runs a line of chat if it is a command, reporting whether it was.
     *
     * Replies go through the connector, and so through the account's one send
     * budget: command replies and relayed chat speak as the same account, and
     * two senders that each stay under Twitch's limit still breach it together.
     */
    public function handle(ChatMessage $message): bool
    {
        if (self::fromSharedChat($message)) {
            return false;
        }

        return $this->dispatcher->dispatch(
            (string) $message->content,
            $this->invocation($message),
            fn (string $text) => $this->connector->send(
                (string) $message->channel,
                $text,
                ['reply_to' => (string) $message->id],
            ),
        );
    }

    /**
     * Whether a message was typed in *another* channel's chat and is being
     * shown here through Twitch's Shared Chat.
     *
     * During a shared session every participating channel receives every
     * participant's messages, tagged with the room they came from. A command
     * typed over there is not addressed to this channel, and its sender's rank
     * was earned over there — so it is not run here at all. (Like any command
     * line, it is not relayed either.)
     */
    public static function fromSharedChat(ChatMessage $message): bool
    {
        $source = (string) ($message->tags['source-room-id'] ?? '');
        $room = (string) ($message->tags['room-id'] ?? '');

        return $source !== '' && $source !== $room;
    }

    /** Who typed a message and where, as the dispatcher needs it. */
    public function invocation(ChatMessage $message): ChatInvocation
    {
        $owner = $this->connector->getConfig()->ownerLogin;
        $isOwner = $owner !== null && $owner !== '' && strcasecmp((string) $message->user, $owner) === 0;
        $roomId = (string) ($message->tags['room-id'] ?? '');

        return new ChatInvocation(
            room: strtolower((string) $message->channel),
            roomId: $roomId !== '' ? $roomId : null,
            invokerName: (string) ($message->display_name ?: $message->user),
            invokerId: (string) ($message->user_id ?: $message->user),
            access: Context::ladder($isOwner, (bool) $message->is_broadcaster, (bool) $message->is_mod),
            message: $message,
        );
    }

    /**
     * Makes a line safe to hand to `Irc::say()`.
     *
     * Kept as a named entry point because it is the project's single most
     * security-relevant function and is tested directly; the implementation is
     * {@see TwitchText::safeLine()}.
     */
    public static function safeLine(string $text): string
    {
        return TwitchText::safeLine($text);
    }
}
