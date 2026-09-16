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

namespace TwitchBot\Relay;

use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Message;
use Twitch\Parts\ChatMessage;
use TwitchBot\Bot;
use TwitchBot\Support\MessageText;

/**
 * The relay itself: Discord chat into Twitch, Twitch chat into Discord.
 *
 * The hard requirement here is that nothing the bot says can come back to it.
 * A bridge that repeats its own output is an infinite loop that gets the
 * account banned from both networks within minutes, so each direction drops its
 * own traffic as early as it can — see {@see shouldRelayFromDiscord()} and the
 * nick check in {@see TwitchGateway::listen()}.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class ChatRelay
{
    public function __construct(private readonly Bot $bot)
    {
    }

    /** Attaches both directions. Call once, after the Twitch gateway is up. */
    public function attach(): void
    {
        $this->bot->on('message', fn (Message $message) => $this->fromDiscord($message));
        $this->bot->twitchGateway()->onChat(fn (ChatMessage $message) => $this->fromTwitch($message));
    }

    // ── Discord → Twitch ───────────────────────────────────────────────

    private function fromDiscord(Message $message): void
    {
        if (! $this->shouldRelayFromDiscord($message)) {
            return;
        }

        $login = $this->bot->getStore()->links()->twitchFor((string) $message->channel_id);

        if ($login === null) {
            return;
        }

        $text = MessageText::forTwitch(
            (string) $message->content,
            $this->authorName($message),
            $this->userNames($message),
            $this->channelNames($message),
            $this->roleNames($message),
            $this->attachmentUrls($message),
        );

        if ($text === null) {
            return;
        }

        $this->bot->twitchGateway()->send($login, $text);
    }

    /**
     * Whether a Discord message is real conversation worth relaying.
     *
     * Four rejections, each for its own reason:
     *
     * - **Webhook messages.** Twitch chat is delivered into Discord *through* a
     *   webhook, so this single check is what stops the loop. It has to come
     *   first and it has to be unconditional.
     * - **Bot authors.** Two bridges sharing a channel would otherwise
     *   ping-pong forever, and a bot's output is rarely what a Twitch chat
     *   wants to read.
     * - **The bot's own messages**, which is belt-and-braces: command replies
     *   are sent as the bot and would otherwise be echoed to Twitch, having
     *   already been said there.
     * - **Commands.** `!title something` is an instruction to the bot, not a
     *   remark; relaying it would put every command into the stream's chat.
     */
    public function shouldRelayFromDiscord(Message $message): bool
    {
        if (($message->webhook_id ?? null) !== null) {
            return false;
        }

        $author = $message->author ?? null;

        if ($author === null || (bool) ($author->bot ?? false)) {
            return false;
        }

        if ((string) ($author->id ?? '') === (string) ($this->bot->id ?? '')) {
            return false;
        }

        return ! $this->isCommand((string) $message->content, $this->bot->getConfig()->discordPrefix);
    }

    // ── Twitch → Discord ───────────────────────────────────────────────

    private function fromTwitch(ChatMessage $message): void
    {
        // The gateway has already dropped our own nick's echo; commands are
        // dropped here so they are handled without also being broadcast.
        if ($this->isCommand((string) $message->content, $this->bot->getConfig()->twitchPrefix)) {
            return;
        }

        $text = MessageText::forDiscord((string) $message->content);

        if ($text === null) {
            return;
        }

        $channels = $this->bot->getStore()->links()->discordFor((string) $message->channel);
        $author = (string) ($message->display_name ?: $message->user);

        foreach ($channels as $channelId) {
            $channel = $this->bot->getChannel($channelId);

            if (! $channel instanceof Channel) {
                $this->bot->getLogger()->debug('[relay] no cached channel ' . $channelId . ' — skipping');

                continue;
            }

            $this->bot->resolveTwitchAvatar((string) $message->user)->then(
                fn (?string $avatar) => $this->bot->delivery()->deliver($channel, $author, $text, $avatar),
            )->then(null, function (\Throwable $e) use ($channel): void {
                $this->bot->getLogger()->warning('[relay] delivery to ' . $channel->id . ' failed: ' . $e->getMessage());

                // Drop the cached webhook. If it was deleted out from under us,
                // every later message would otherwise keep failing against the
                // same dead handle; forgetting it means the next one recreates.
                $this->bot->delivery()->forget($channel);
            });
        }
    }

    // ── Shared ─────────────────────────────────────────────────────────

    /**
     * Whether a line is addressed to the bot rather than to the channel.
     *
     * Only a *registered* command counts. Chat is full of `!` — "yes!!!", or
     * another bot's `!drop` — and treating all of it as a command would quietly
     * stop relaying a slice of ordinary conversation.
     */
    public function isCommand(string $content, string $prefix): bool
    {
        if ($prefix === '' || ! str_starts_with($content, $prefix)) {
            return false;
        }

        $rest = substr($content, strlen($prefix));
        $name = strtolower(strtok($rest, " \t") ?: '');

        return $name !== '' && $this->bot->getActions()->has($name);
    }

    private function authorName(Message $message): string
    {
        $member = $message->member ?? null;

        return (string) ($member?->nick
            ?? $message->author->displayname
            ?? $message->author->username
            ?? 'someone');
    }

    /**
     * The CDN link for each attachment, in the order they were posted.
     *
     * `url` rather than `proxy_url`: both are signed and both expire, but `url`
     * is the canonical one, and the proxy adds nothing for a recipient who is
     * going to open it in a browser.
     *
     * @return list<string>
     */
    private function attachmentUrls(Message $message): array
    {
        $urls = [];

        foreach ($message->attachments ?? [] as $attachment) {
            $url = (string) ($attachment->url ?? '');

            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /** @return array<string, string> */
    private function userNames(Message $message): array
    {
        $names = [];

        foreach ($message->mentions ?? [] as $user) {
            $names[(string) $user->id] = (string) ($user->displayname ?? $user->username ?? 'someone');
        }

        return $names;
    }

    /** @return array<string, string> */
    private function channelNames(Message $message): array
    {
        $names = [];
        $guild = $message->guild ?? null;

        if ($guild === null) {
            return $names;
        }

        foreach ($guild->channels ?? [] as $channel) {
            $names[(string) $channel->id] = (string) $channel->name;
        }

        return $names;
    }

    /** @return array<string, string> */
    private function roleNames(Message $message): array
    {
        $names = [];
        $guild = $message->guild ?? null;

        if ($guild === null) {
            return $names;
        }

        foreach ($guild->roles ?? [] as $role) {
            $names[(string) $role->id] = (string) $role->name;
        }

        return $names;
    }
}
