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

use Discord\Builders\MessageBuilder;
use Discord\Discord;
use Discord\Parts\Channel\Channel;
use Discord\Parts\Channel\Webhook;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Posts Twitch chat into Discord.
 *
 * Uses a webhook so each relayed line carries the Twitch user's own name and
 * avatar instead of arriving as a wall of identical bot messages. That is not
 * only cosmetic: webhook messages carry a `webhook_id`, which is how the
 * bridge recognises its own output and refuses to relay it back to Twitch.
 *
 * Webhooks need **Manage Webhooks**. When that is missing — or creation fails
 * for any other reason — delivery falls back to an ordinary bot message with
 * the author's name inline, so a misconfigured server degrades to an uglier
 * bridge rather than a silent one.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class WebhookDelivery
{
    public const WEBHOOK_NAME = 'TwitchBot';

    /** Discord rejects a webhook username longer than this. */
    public const USERNAME_LIMIT = 80;

    /** @var array<string, Webhook|false> Channel id => webhook, or false when we know we can't have one. */
    private array $cache = [];

    public function __construct(private readonly Discord $discord)
    {
    }

    /**
     * Delivers one Twitch message to one Discord channel.
     *
     * `allowed_mentions` is empty on every path: Twitch chat is untrusted
     * input, and a viewer typing `@everyone` must not ping a Discord server.
     */
    public function deliver(Channel $channel, string $author, string $text, ?string $avatarUrl = null): PromiseInterface
    {
        return $this->webhookFor($channel)->then(
            function (Webhook $webhook) use ($author, $text, $avatarUrl): PromiseInterface {
                $payload = [
                    'content' => $text,
                    'username' => self::safeUsername($author),
                    'allowed_mentions' => ['parse' => []],
                ];

                if ($avatarUrl !== null) {
                    $payload['avatar_url'] = $avatarUrl;
                }

                return $webhook->execute($payload);
            },
            fn () => $this->fallback($channel, $author, $text),
        );
    }

    /** Forgets a channel's cached webhook — call when delivery starts failing. */
    public function forget(Channel $channel): void
    {
        unset($this->cache[(string) $channel->id]);
    }

    /** @return PromiseInterface<Webhook> */
    private function webhookFor(Channel $channel): PromiseInterface
    {
        $id = (string) $channel->id;

        if (isset($this->cache[$id])) {
            return $this->cache[$id] === false
                ? reject(new \RuntimeException('no webhook available'))
                : resolve($this->cache[$id]);
        }

        return $channel->webhooks->freshen()->then(
            function ($webhooks) use ($channel, $id): PromiseInterface {
                foreach ($webhooks as $webhook) {
                    // Reuse only our own: another integration's webhook is not
                    // ours to post through.
                    if ($webhook->name === self::WEBHOOK_NAME
                        && (string) ($webhook->application_id ?? '') === (string) ($this->discord->application->id ?? '')) {
                        $this->cache[$id] = $webhook;

                        return resolve($webhook);
                    }
                }

                return $this->create($channel);
            },
            fn () => $this->create($channel),
        );
    }

    /** @return PromiseInterface<Webhook> */
    private function create(Channel $channel): PromiseInterface
    {
        $id = (string) $channel->id;

        return $channel->webhooks->save(
            $channel->webhooks->create(['name' => self::WEBHOOK_NAME]),
            'Twitch chat relay',
        )->then(
            function (Webhook $webhook) use ($id): Webhook {
                $this->cache[$id] = $webhook;

                return $webhook;
            },
            function (\Throwable $e) use ($id): never {
                // Remember the failure so every relayed line doesn't retry a
                // permission we demonstrably lack.
                $this->cache[$id] = false;

                throw $e;
            },
        );
    }

    private function fallback(Channel $channel, string $author, string $text): PromiseInterface
    {
        return $channel->sendMessage(
            MessageBuilder::new()
                ->setContent(sprintf('**%s:** %s', self::escape($author), $text))
                ->setAllowedMentions(['parse' => []]),
        );
    }

    /**
     * Discord rejects webhook usernames containing "discord", and caps them at
     * 80 characters. A Twitch display name can be neither, but the bridge
     * should not drop a message over it.
     */
    public static function safeUsername(string $author): string
    {
        $suffix = ' (twitch)';
        $name = trim(str_ireplace('discord', 'disc*rd', $author));
        $name = mb_substr($name === '' ? 'twitch user' : $name, 0, self::USERNAME_LIMIT - mb_strlen($suffix), 'UTF-8');

        return $name . $suffix;
    }

    /** Neutralises Discord markdown in a name shown in the fallback path. */
    private static function escape(string $text): string
    {
        return preg_replace('/([*_~`|\\\\])/', '\\\\$1', $text) ?? $text;
    }
}
