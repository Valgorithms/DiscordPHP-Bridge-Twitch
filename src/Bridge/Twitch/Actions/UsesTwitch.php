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

namespace Bridge\Twitch\Actions;

use Bridge\Command\ActionError;
use Bridge\Command\Arguments;
use Bridge\Command\Context;
use Bridge\Twitch\TwitchConnector;
use React\Promise\PromiseInterface;
use Twitch\Http\Exceptions\HttpException;
use Twitch\Http\Exceptions\MissingScopeException;

/**
 * Reaches the Twitch client from inside an action.
 *
 * An action is handed a {@see Context}, which knows the bot but deliberately
 * not the network — that is what lets the same catalogue serve every chat. A
 * Twitch action does need the Twitch client, so it asks the bot for the
 * connector by name.
 *
 * It can genuinely be absent: the connector is installed only when the
 * environment carries Twitch credentials, and it can fail to start while the
 * rest of the bot runs on. Saying so is better than a `TypeError` two frames
 * down, and it is the same answer somebody typing `!twitch title` into a
 * Telegram group deserves when there is no Twitch side configured at all.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
trait UsesTwitch
{
    private function twitch(Context $context): TwitchConnector
    {
        $connector = $context->bot->connector(TwitchConnector::NAME);

        if (! $connector instanceof TwitchConnector) {
            throw new ActionError('the Twitch side is not connected, so that cannot be done right now.');
        }

        return $connector;
    }

    /**
     * Wraps a handler so a Helix refusal reaches whoever typed the command as
     * a sentence, rather than as "that did not work".
     *
     * @param  callable(Context, Arguments): mixed $handler
     * @return \Closure(Context, Arguments): mixed
     */
    private function explained(callable $handler): \Closure
    {
        return static function (Context $context, Arguments $arguments) use ($handler): mixed {
            try {
                $result = $handler($context, $arguments);
            } catch (\Throwable $e) {
                throw self::explainTwitch($e);
            }

            return $result instanceof PromiseInterface
                ? $result->then(null, static fn (\Throwable $e) => throw self::explainTwitch($e))
                : $result;
        };
    }

    /**
     * What Twitch's refusal means to the person who asked.
     *
     * A 4xx from Helix carries a message written for people — "The user
     * specified in the user_id field is already banned." — and nothing secret,
     * so it is passed on. Anything else (a 5xx, a dropped connection) is left
     * for the adapter to log and summarise.
     */
    private static function explainTwitch(\Throwable $e): \Throwable
    {
        if ($e instanceof ActionError) {
            return $e;
        }

        if ($e instanceof MissingScopeException) {
            return new ActionError(sprintf(
                'the bot token is missing the %s scope. Re-authorize it with that scope included.',
                $e->scopes !== [] ? implode(' or ', $e->scopes) : 'required',
            ), 0, $e);
        }

        if ($e instanceof HttpException && $e->status >= 400 && $e->status < 500) {
            $said = trim((string) preg_replace('/^HTTP \d+:\s*/', '', $e->getMessage()));

            return new ActionError(sprintf(
                'Twitch refused that%s',
                $said === '' ? '.' : ': ' . rtrim($said, '.') . '.',
            ), 0, $e);
        }

        return $e;
    }
}
