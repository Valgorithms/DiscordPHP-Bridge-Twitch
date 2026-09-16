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

use React\Promise\PromiseInterface;

use function React\Promise\resolve;

use Twitch\Chat\Command as TwitchCommand;
use Twitch\Chat\CommandClient;
use Twitch\Parts\ChatMessage;
use TwitchBot\Bot;
use TwitchBot\Support\Format;
use TwitchBot\Support\MessageText;

/**
 * Registers the action catalogue into TwitchPHP's `CommandClient`.
 *
 * The Twitch side is the simpler of the two in one respect — the channel a
 * command was typed in *is* the channel it acts on, and its id arrives in the
 * `room-id` tag, so no lookup is needed — and the harder one in another: there
 * is nowhere private to answer, and every reply is one CRLF away from being an
 * IRC command.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class TwitchAdapter
{
    public function __construct(
        private readonly Bot $bot,
        private readonly ActionRegistry $registry,
        private readonly CommandClient $commands,
    ) {
    }

    /** Wires every Twitch-available action into the command client. */
    public function register(): void
    {
        foreach ($this->registry->forSurface(Surface::Twitch) as $action) {
            $this->commands->command(
                $action->name,
                fn (ChatMessage $message, array $args) => $this->invoke($action, $message, $args),
                $action->aliases,
                $action->cooldown,
                $this->permissionFor($action),
                $action->description,
            );
        }
    }

    /**
     * Translates a rung of {@see Access} into what `CommandClient` understands.
     *
     * Everything but `Owner` maps onto a badge level it already knows. `Owner`
     * is not a chat role at all, so it becomes a predicate over the sender's
     * login — and when no owner is configured, one that always refuses. That
     * is the safe default: these are the actions that can reach any endpoint.
     */
    private function permissionFor(Action $action): mixed
    {
        $owner = $this->bot->getConfig()->twitchOwnerLogin;

        return match ($action->access) {
            Access::Everyone => TwitchCommand::EVERYONE,
            Access::Moderator => TwitchCommand::MODERATOR,
            Access::Broadcaster => TwitchCommand::BROADCASTER,
            Access::Owner => static fn (ChatMessage $m): bool => $owner !== null
                && $owner !== ''
                && strcasecmp((string) $m->user, $owner) === 0,
        };
    }

    private function invoke(Action $action, ChatMessage $message, array $args): void
    {
        // A sensitive answer has nowhere safe to go on Twitch: chat is public,
        // and whispers from a bot are unreliable and rate-limited. Refuse.
        if ($action->sensitive) {
            $this->say($message, 'that one can only be run from Discord — the answer must not be posted in chat.');

            return;
        }

        $context = $this->contextFor($message);
        $arguments = Arguments::fromTokens($args);

        $this->settle($action->run($context, $arguments))->then(
            fn (?string $reply) => $reply === null ? null : $this->say($message, $reply),
            fn (\Throwable $e) => $this->fail($message, $action, $e),
        );
    }

    private function contextFor(ChatMessage $message): Context
    {
        $owner = $this->bot->getConfig()->twitchOwnerLogin;
        $isOwner = $owner !== null && $owner !== '' && strcasecmp((string) $message->user, $owner) === 0;

        $access = Context::twitchAccess(
            (bool) $message->is_broadcaster,
            (bool) $message->is_mod,
            $isOwner,
        );

        // `room-id` is the broadcaster's user id, already on the message — the
        // Discord side has to go and look this up.
        $roomId = (string) ($message->tags['room-id'] ?? '');

        return new Context(
            $this->bot,
            Surface::Twitch,
            $access,
            (string) ($message->display_name ?: $message->user),
            (string) $message->user_id,
            $roomId !== '' ? $roomId : null,
            (string) $message->channel,
            true,
            $message,
        );
    }

    /** @return PromiseInterface<string|null> */
    private function settle(mixed $result): PromiseInterface
    {
        if ($result instanceof PromiseInterface) {
            return $result->then(static fn ($value) => $value === null ? null : (string) $value);
        }

        return resolve($result === null ? null : (string) $result);
    }

    private function fail(ChatMessage $message, Action $action, \Throwable $e): void
    {
        if ($e instanceof ActionError) {
            $this->say($message, $e->getMessage());

            return;
        }

        $this->bot->getLogger()->error(sprintf(
            '[twitch] action %s failed: %s',
            $action->name,
            $e->getMessage(),
        ), ['exception' => $e]);

        $this->say($message, 'that did not work.');
    }

    /**
     * Sends a reply, through the gateway's budget where one is up.
     *
     * Routing through the gateway matters: command replies and relayed chat
     * both speak as the same account, and Twitch's 20-per-30-seconds limit
     * mutes that account for thirty minutes rather than dropping a message. Two
     * independent senders would each stay under the limit and still breach it
     * together.
     */
    private function say(ChatMessage $message, string $text): void
    {
        $text = self::safeLine($text);

        if ($text === '') {
            return;
        }

        $gateway = $this->bot->twitchGatewayOrNull();

        if ($gateway !== null) {
            $gateway->send((string) $message->channel, $text, (string) $message->id);

            return;
        }

        $message->reply($text);
    }

    /**
     * Makes a line safe to hand to `Irc::say()`.
     *
     * Two separate hazards. CR and LF would terminate the `PRIVMSG` line and
     * let the remainder be parsed as a fresh IRC command — so they are stripped
     * rather than escaped. And Twitch reads a leading `/` or `.` as a chat
     * command, so a reply that happens to begin with one (an API error quoting
     * a path, say) would be executed instead of said; a leading zero-width
     * space would be invisible but is also silently dropped by some clients, so
     * the character is prefixed with a space instead.
     */
    public static function safeLine(string $text): string
    {
        $text = MessageText::sanitizeIrc($text);

        if ($text !== '' && ($text[0] === '/' || $text[0] === '.')) {
            $text = ' ' . $text;
        }

        return Format::clamp($text, Surface::Twitch);
    }
}
