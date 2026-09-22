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
use Bridge\Command\Action;
use Bridge\Command\ActionError;
use Bridge\Command\Arguments;
use Bridge\Command\Context;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

use Twitch\Chat\Command as TwitchCommand;
use Twitch\Chat\CommandClient;
use Twitch\Parts\ChatMessage;

/**
 * Registers the whole catalogue into TwitchPHP's `CommandClient`.
 *
 * The *whole* catalogue, deliberately: this is where `!telegram send` typed in
 * a Twitch chat becomes a message in a Telegram group. One bot rather than two
 * is only worth anything if the commands cross over, so every connector's
 * actions are offered here, not just this one's.
 *
 * Twitch is the simpler side in one respect — the channel a command was typed
 * in *is* the channel it acts on, and its id arrives in the `room-id` tag, so
 * no lookup is needed — and the harder one in another: there is nowhere private
 * to answer, and every reply is one CRLF away from being an IRC command.
 *
 * ## Names are qualified here too
 *
 * `CommandClient` matches on a single word, so each action is registered under
 * its qualifier and dispatched from there: typing `!twitch` alone lists what is
 * under it, and `!twitch title` runs the action. Dropping the qualifier because
 * "this is the Twitch chat, obviously" would mean `!title` doing different
 * things depending on which room it was typed in.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class TwitchAdapter
{
    public function __construct(
        private readonly TwitchConnector $connector,
        private readonly Bot $bot,
        private readonly CommandClient $commands,
    ) {
    }

    /** Wires every action available on this surface into the command client. */
    public function register(): void
    {
        $surface = $this->connector->surface();

        foreach ($this->byQualifier() as $qualifier => $actions) {
            $this->commands->command(
                $qualifier,
                fn (ChatMessage $message, array $args) => $this->route($qualifier, $message, $args),
                [],
                0,
                TwitchCommand::EVERYONE,
                sprintf('%s commands — try "%s%s" on its own.', $qualifier, $this->connector->getConfig()->prefix, $qualifier),
            );
        }

        $this->bot->getLogger()->info(sprintf(
            '[twitch] %d command(s) over %d group(s) registered in chat',
            count($this->bot->getActions()->forSurface($surface)),
            count($this->byQualifier()),
        ));
    }

    /**
     * Every action available in Twitch chat, grouped by the word that leads it.
     *
     * @return array<string, list<Action>>
     */
    private function byQualifier(): array
    {
        $grouped = [];

        foreach ($this->bot->getActions()->forSurface($this->connector->surface()) as $action) {
            $grouped[$action->qualifier][] = $action;
        }

        return $grouped;
    }

    /**
     * Resolves the second word to an action and runs it.
     *
     * Permission is checked here rather than by `CommandClient`, because one
     * registered word now covers a whole qualifier whose actions each want a
     * different rung.
     */
    private function route(string $qualifier, ChatMessage $message, array $args): void
    {
        $context = $this->contextFor($message);
        [$action, $rest] = $this->bot->getActions()->resolve([$qualifier, ...$args]);

        if ($action === null) {
            $this->say($message, $this->listUnder($qualifier, $context));

            return;
        }

        if (! $context->access->satisfies($action->access)) {
            $this->say($message, sprintf('that one is limited to %s.', $action->access->label()));

            return;
        }

        // A sensitive answer has nowhere safe to go on Twitch: chat is public,
        // and whispers from a bot are unreliable and rate-limited. Refuse.
        if ($action->sensitive) {
            $this->say($message, 'that one can only be run from Discord — the answer must not be posted in chat.');

            return;
        }

        $this->settle($action->run($context, Arguments::fromTokens($rest)))->then(
            fn (?string $reply) => $reply === null ? null : $this->say($message, $reply),
            fn (\Throwable $e) => $this->fail($message, $action, $e),
        );
    }

    /** What somebody who typed a bare qualifier probably wanted. */
    private function listUnder(string $qualifier, Context $context): string
    {
        $names = [];

        foreach ($this->bot->getActions()->forQualifier($qualifier) as $action) {
            if ($action->availableOn($context->surface) && $context->access->satisfies($action->access)) {
                $names[] = $action->name;
            }
        }

        return $names === []
            ? sprintf('nothing under %s you can run.', $qualifier)
            : sprintf('%s: %s', $qualifier, implode(', ', $names));
    }

    private function contextFor(ChatMessage $message): Context
    {
        $owner = $this->connector->getConfig()->ownerLogin;
        $isOwner = $owner !== null && $owner !== '' && strcasecmp((string) $message->user, $owner) === 0;

        $access = Context::ladder(
            $isOwner,
            (bool) $message->is_broadcaster,
            (bool) $message->is_mod,
        );

        // `room-id` is the broadcaster's user id, already on the message — the
        // Discord side has to go and look this up.
        $roomId = (string) ($message->tags['room-id'] ?? '');

        return new Context(
            $this->bot,
            $this->connector->surface(),
            $access,
            (string) ($message->display_name ?: $message->user),
            (string) $message->user_id,
            TwitchConnector::NAME,
            (string) $message->channel,
            $roomId !== '' ? $roomId : null,
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
            $action->qualified(),
            $e->getMessage(),
        ), ['exception' => $e]);

        $this->say($message, 'that did not work.');
    }

    /**
     * Sends a reply, through the connector's own budget.
     *
     * Routing through the connector matters: command replies and relayed chat
     * both speak as the same account, and Twitch's 20-per-30-seconds limit
     * mutes that account for thirty minutes rather than dropping a message. Two
     * independent senders would each stay under the limit and still breach it
     * together.
     */
    private function say(ChatMessage $message, string $text): void
    {
        $this->connector->send(
            (string) $message->channel,
            $text,
            ['reply_to' => (string) $message->id],
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
