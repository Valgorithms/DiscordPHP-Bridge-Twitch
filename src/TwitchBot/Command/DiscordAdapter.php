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

use Discord\Builders\MessageBuilder;
use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

use TwitchBot\Bot;
use TwitchBot\Support\Format;
use TwitchBot\Support\MessageText;
use TwitchBot\Support\Permissions;

/**
 * Registers the action catalogue into DiscordPHP's `MessageCommandClient`.
 *
 * Everything platform-specific about the Discord side lives here: reading a
 * member's permissions, working out which Twitch channel a Discord channel
 * stands for, and getting a reply back out without pinging the server.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class DiscordAdapter
{
    public function __construct(
        private readonly Bot $bot,
        private readonly ActionRegistry $registry,
    ) {
    }

    /** Wires every Discord-available action into the command client. */
    public function register(): void
    {
        foreach ($this->registry->forSurface(Surface::Discord) as $action) {
            $this->bot->registerCommand(
                $action->name,
                fn (Message $message, array $args) => $this->invoke($action, $message, $args),
                [
                    'description' => $action->description !== '' ? $action->description : 'No description provided.',
                    'usage' => $action->usage,
                    'aliases' => $action->aliases,
                    'cooldown' => $action->cooldown,
                ],
            );
        }
    }

    private function invoke(Action $action, Message $message, array $args): void
    {
        $access = Permissions::accessFor($message, $this->bot->getConfig()->discordOwnerId);

        if (! $access->satisfies($action->access)) {
            $this->say($message, sprintf('that command is limited to %s.', $action->access->label()));

            return;
        }

        $arguments = Arguments::fromTokens($args);

        // A sensitive action can return a stream key or a token. There is no
        // quiet corner of a guild channel, so it is answered in a DM and the
        // channel is told only that it happened.
        $private = $action->sensitive && ($message->guild_id !== null);

        $this->contextFor($message, $access, $arguments, ! $private)->then(
            function (Context $context) use ($action, $arguments, $message, $private): void {
                $this->settle($action->run($context, $arguments))->then(
                    fn (?string $reply) => $reply === null ? null : $this->deliver($message, $reply, $private),
                    fn (\Throwable $e) => $this->fail($message, $action, $e),
                );
            },
            fn (\Throwable $e) => $this->fail($message, $action, $e),
        );
    }

    /**
     * Builds the invocation context, resolving which Twitch channel this
     * Discord channel acts on.
     *
     * An explicit `channel=<login>` wins over the link, so a server that
     * bridges one channel can still ask about another without rewiring it.
     *
     * @return PromiseInterface<Context>
     */
    private function contextFor(Message $message, Access $access, Arguments $arguments, bool $isPublic): PromiseInterface
    {
        $explicit = $arguments->named('channel');
        $login = $explicit !== null
            ? MessageText::normalizeLogin($explicit)
            : $this->bot->getStore()->links()->twitchFor((string) $message->channel_id);

        $base = new Context(
            $this->bot,
            Surface::Discord,
            $access,
            (string) ($message->author->displayname ?? $message->author->username ?? 'someone'),
            (string) ($message->author->id ?? ''),
            null,
            null,
            $isPublic,
            $message,
        );

        if ($login === null || $login === '') {
            return resolve($base);
        }

        return $this->bot->resolveTwitchUser($login)->then(
            static fn (?array $user) => $user === null
                ? $base->withBroadcaster(null, $login)
                : $base->withBroadcaster($user['id'], $user['login']),
        );
    }

    /** Normalises a handler's `string|null|PromiseInterface` into a promise. */
    private function settle(mixed $result): PromiseInterface
    {
        if ($result instanceof PromiseInterface) {
            return $result->then(static fn ($value) => $value === null ? null : (string) $value);
        }

        return resolve($result === null ? null : (string) $result);
    }

    private function deliver(Message $message, string $reply, bool $private): void
    {
        if (! $private) {
            $this->say($message, $reply);

            return;
        }

        $author = $message->author;

        if ($author === null) {
            return;
        }

        $author->sendMessage($this->builder($reply))->then(
            fn () => $this->say($message, 'sent you that in a DM — it contains something that should not be posted in a channel.'),
            fn () => $this->say($message, 'that answer contains a secret and your DMs are closed, so it has not been sent.'),
        );
    }

    private function fail(Message $message, Action $action, \Throwable $e): void
    {
        if ($e instanceof ActionError) {
            $this->say($message, $e->getMessage());

            return;
        }

        // Anything else may carry internals. Log it in full; say only that it
        // broke.
        $this->bot->getLogger()->error(sprintf(
            '[discord] action %s failed: %s',
            $action->name,
            $e->getMessage(),
        ), ['exception' => $e]);

        $this->say($message, 'that did not work. The details are in the bot log.');
    }

    private function say(Message $message, string $text): void
    {
        $message->reply($this->builder($text))->then(null, function (\Throwable $e): void {
            $this->bot->getLogger()->debug('[discord] could not reply: ' . $e->getMessage());
        });
    }

    /** Every outbound message: clamped to Discord's limit, and pinging nobody. */
    private function builder(string $text): MessageBuilder
    {
        return MessageBuilder::new()
            ->setContent(Format::clamp($text, Surface::Discord))
            ->setAllowedMentions(['parse' => []]);
    }
}
