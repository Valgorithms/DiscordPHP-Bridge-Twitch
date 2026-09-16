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

namespace TwitchBot\Actions;

use React\Promise\PromiseInterface;
use TwitchBot\Command\Access;
use TwitchBot\Command\Action;
use TwitchBot\Command\ActionError;
use TwitchBot\Command\ActionProvider;
use TwitchBot\Command\Arguments;
use TwitchBot\Command\Context;

/**
 * Moderation, chat settings and the broadcast controls.
 *
 * Everything here acts as the bot's own account in its capacity as a moderator,
 * so `moderator_id` is always the bot's user id — Twitch requires the moderator
 * to be the authenticated user, and a token cannot moderate on someone else's
 * behalf.
 *
 * These are the commands with real consequences, so the permission rungs are
 * set deliberately rather than uniformly: timing someone out is a moderator's
 * job, but handing out the moderator badge, raiding, and running an ad break
 * are the broadcaster's.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class ModerationActions implements ActionProvider
{
    /** Twitch caps a timeout at fourteen days. */
    private const MAX_TIMEOUT = 1_209_600;

    public function actions(): array
    {
        return [
            new Action('ban', $this->ban(...), 'Ban someone', '<user> [reason]', access: Access::Moderator, group: 'moderation'),
            new Action('unban', $this->unban(...), 'Lift a ban', '<user>', access: Access::Moderator, group: 'moderation'),
            new Action('timeout', $this->timeout(...), 'Time someone out', '<user> [seconds] [reason]', access: Access::Moderator, aliases: ['to'], group: 'moderation'),
            new Action('vip', $this->vip(...), 'Give VIP', '<user>', access: Access::Broadcaster, group: 'moderation'),
            new Action('unvip', $this->unvip(...), 'Take VIP away', '<user>', access: Access::Broadcaster, group: 'moderation'),
            new Action('mod', $this->mod(...), 'Give moderator', '<user>', access: Access::Broadcaster, group: 'moderation'),
            new Action('unmod', $this->unmod(...), 'Take moderator away', '<user>', access: Access::Broadcaster, group: 'moderation'),
            new Action('clear', $this->clear(...), 'Clear the chat', access: Access::Moderator, group: 'moderation'),
            new Action('announce', $this->announce(...), 'Post a highlighted announcement', '<message>', access: Access::Moderator, group: 'moderation'),
            new Action('shoutout', $this->shoutout(...), 'Shout out another channel', '<channel>', access: Access::Moderator, aliases: ['so'], cooldown: 120, group: 'moderation'),
            new Action('slow', $this->slow(...), 'Slow mode on (seconds) or off', '[seconds|off]', access: Access::Moderator, group: 'moderation'),
            new Action('subonly', $this->subonly(...), 'Subscriber-only chat on or off', '[on|off]', access: Access::Moderator, group: 'moderation'),
            new Action('emoteonly', $this->emoteonly(...), 'Emote-only chat on or off', '[on|off]', access: Access::Moderator, group: 'moderation'),
            // No `followers` alias: that name belongs to the read-only
            // follower count in StreamActions, and the registry rejects the
            // clash rather than letting one silently shadow the other.
            new Action('followersonly', $this->followersonly(...), 'Followers-only chat, with an optional minutes threshold', '[minutes|off]', access: Access::Moderator, aliases: ['followermode'], group: 'moderation'),
            new Action('raid', $this->raid(...), 'Raid another channel', '<channel>', access: Access::Broadcaster, group: 'moderation'),
            new Action('unraid', $this->unraid(...), 'Cancel a pending raid', access: Access::Broadcaster, group: 'moderation'),
            new Action('commercial', $this->commercial(...), 'Start an ad break', '[seconds]', access: Access::Broadcaster, group: 'moderation'),
        ];
    }

    // ── Bans and roles ─────────────────────────────────────────────────

    /** @return PromiseInterface<string> */
    private function ban(Context $context, Arguments $arguments): PromiseInterface
    {
        $reason = $arguments->rest(1);

        return $this->onUser($context, $arguments, fn (string $broadcaster, array $user) =>
            $context->bot->getTwitch()->moderation
                ->ban($broadcaster, $this->moderatorId($context), $user['id'], $reason !== '' ? $reason : null)
                ->then(static fn (): string => sprintf('banned %s.', $user['display_name'])));
    }

    /** @return PromiseInterface<string> */
    private function unban(Context $context, Arguments $arguments): PromiseInterface
    {
        return $this->onUser($context, $arguments, fn (string $broadcaster, array $user) =>
            $context->bot->getTwitch()->moderation
                ->unban($broadcaster, $this->moderatorId($context), $user['id'])
                ->then(static fn (): string => sprintf('unbanned %s.', $user['display_name'])));
    }

    /** @return PromiseInterface<string> */
    private function timeout(Context $context, Arguments $arguments): PromiseInterface
    {
        $seconds = 600;
        $reasonFrom = 1;

        // `timeout bob 300 spam` and `timeout bob spam` both have to work, so
        // the duration is only consumed when it actually looks like one.
        $second = (string) $arguments->get(1, '');
        if ($second !== '' && preg_match('/^\d+$/', $second) === 1) {
            $seconds = (int) $second;
            $reasonFrom = 2;
        }

        if ($seconds < 1 || $seconds > self::MAX_TIMEOUT) {
            throw new ActionError(sprintf('a timeout has to be between 1 second and 14 days (%d seconds).', self::MAX_TIMEOUT));
        }

        $reason = $arguments->rest($reasonFrom);

        return $this->onUser($context, $arguments, fn (string $broadcaster, array $user) =>
            $context->bot->getTwitch()->moderation
                ->timeout($broadcaster, $this->moderatorId($context), $user['id'], $seconds, $reason !== '' ? $reason : null)
                ->then(static fn (): string => sprintf('timed %s out for %ds.', $user['display_name'], $seconds)));
    }

    /** @return PromiseInterface<string> */
    private function vip(Context $context, Arguments $arguments): PromiseInterface
    {
        return $this->onUser($context, $arguments, fn (string $broadcaster, array $user) =>
            $context->bot->getTwitch()->moderation->addVip($broadcaster, $user['id'])
                ->then(static fn (): string => sprintf('%s is now a VIP.', $user['display_name'])));
    }

    /** @return PromiseInterface<string> */
    private function unvip(Context $context, Arguments $arguments): PromiseInterface
    {
        return $this->onUser($context, $arguments, fn (string $broadcaster, array $user) =>
            $context->bot->getTwitch()->moderation->removeVip($broadcaster, $user['id'])
                ->then(static fn (): string => sprintf('%s is no longer a VIP.', $user['display_name'])));
    }

    /** @return PromiseInterface<string> */
    private function mod(Context $context, Arguments $arguments): PromiseInterface
    {
        return $this->onUser($context, $arguments, fn (string $broadcaster, array $user) =>
            $context->bot->getTwitch()->moderation->addModerator($broadcaster, $user['id'])
                ->then(static fn (): string => sprintf('%s is now a moderator.', $user['display_name'])));
    }

    /** @return PromiseInterface<string> */
    private function unmod(Context $context, Arguments $arguments): PromiseInterface
    {
        return $this->onUser($context, $arguments, fn (string $broadcaster, array $user) =>
            $context->bot->getTwitch()->moderation->removeModerator($broadcaster, $user['id'])
                ->then(static fn (): string => sprintf('%s is no longer a moderator.', $user['display_name'])));
    }

    // ── Chat ───────────────────────────────────────────────────────────

    /** @return PromiseInterface<string> */
    private function clear(Context $context, Arguments $arguments): PromiseInterface
    {
        return $context->bot->getTwitch()->moderation
            ->deleteMessages($context->requireBroadcaster(), $this->moderatorId($context))
            ->then(static fn (): string => 'chat cleared.');
    }

    /** @return PromiseInterface<string> */
    private function announce(Context $context, Arguments $arguments): PromiseInterface
    {
        $text = $arguments->rest();

        if ($text === '') {
            throw new ActionError('announce what? `announce <message>`');
        }

        return $context->bot->getTwitch()->chat
            ->announce($context->requireBroadcaster(), $this->moderatorId($context), $text)
            ->then(static fn (): string => 'announced.');
    }

    /** @return PromiseInterface<string> */
    private function shoutout(Context $context, Arguments $arguments): PromiseInterface
    {
        return $this->onUser($context, $arguments, fn (string $broadcaster, array $user) =>
            $context->bot->getTwitch()->chat
                ->shoutout($broadcaster, $user['id'], $this->moderatorId($context))
                ->then(static fn (): string => sprintf(
                    'shouted out %s — https://twitch.tv/%s',
                    $user['display_name'],
                    $user['login'],
                )));
    }

    /** @return PromiseInterface<string> */
    private function slow(Context $context, Arguments $arguments): PromiseInterface
    {
        $value = strtolower((string) $arguments->get(0, '30'));

        if ($this->isOff($value)) {
            return $this->settings($context, ['slow_mode' => false])
                ->then(static fn (): string => 'slow mode off.');
        }

        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new ActionError('slow mode takes a number of seconds, or `off`.');
        }

        $seconds = (int) $value;

        if ($seconds < 3 || $seconds > 120) {
            throw new ActionError('slow mode has to be between 3 and 120 seconds.');
        }

        return $this->settings($context, ['slow_mode' => true, 'slow_mode_wait_time' => $seconds])
            ->then(static fn (): string => sprintf('slow mode on, %ds between messages.', $seconds));
    }

    /** @return PromiseInterface<string> */
    private function subonly(Context $context, Arguments $arguments): PromiseInterface
    {
        $on = ! $this->isOff((string) $arguments->get(0, 'on'));

        return $this->settings($context, ['subscriber_mode' => $on])
            ->then(static fn (): string => 'subscriber-only chat ' . ($on ? 'on.' : 'off.'));
    }

    /** @return PromiseInterface<string> */
    private function emoteonly(Context $context, Arguments $arguments): PromiseInterface
    {
        $on = ! $this->isOff((string) $arguments->get(0, 'on'));

        return $this->settings($context, ['emote_mode' => $on])
            ->then(static fn (): string => 'emote-only chat ' . ($on ? 'on.' : 'off.'));
    }

    /** @return PromiseInterface<string> */
    private function followersonly(Context $context, Arguments $arguments): PromiseInterface
    {
        $value = strtolower((string) $arguments->get(0, '0'));

        if ($this->isOff($value)) {
            return $this->settings($context, ['follower_mode' => false])
                ->then(static fn (): string => 'followers-only chat off.');
        }

        if (preg_match('/^\d+$/', $value) !== 1) {
            throw new ActionError('followers-only takes a number of minutes, or `off`.');
        }

        $minutes = (int) $value;

        return $this->settings($context, ['follower_mode' => true, 'follower_mode_duration' => $minutes])
            ->then(static fn (): string => $minutes === 0
                ? 'followers-only chat on.'
                : sprintf('followers-only chat on, %d minute%s minimum.', $minutes, $minutes === 1 ? '' : 's'));
    }

    // ── Broadcast ──────────────────────────────────────────────────────

    /** @return PromiseInterface<string> */
    private function raid(Context $context, Arguments $arguments): PromiseInterface
    {
        return $this->onUser($context, $arguments, fn (string $broadcaster, array $user) =>
            $context->bot->getTwitch()->raids->start($broadcaster, $user['id'])
                ->then(static fn (): string => sprintf('raiding %s — it starts in 90 seconds.', $user['display_name'])));
    }

    /** @return PromiseInterface<string> */
    private function unraid(Context $context, Arguments $arguments): PromiseInterface
    {
        return $context->bot->getTwitch()->raids->cancel($context->requireBroadcaster())
            ->then(static fn (): string => 'raid cancelled.');
    }

    /** @return PromiseInterface<string> */
    private function commercial(Context $context, Arguments $arguments): PromiseInterface
    {
        $seconds = (int) ($arguments->get(0, '60') ?? '60');

        if ($seconds < 1 || $seconds > 180) {
            throw new ActionError('an ad break has to be between 1 and 180 seconds.');
        }

        return $context->bot->getTwitch()->ads->startCommercial($context->requireBroadcaster(), $seconds)
            ->then(static fn (): string => sprintf('started a %ds ad break.', $seconds));
    }

    // ── Shared ─────────────────────────────────────────────────────────

    /**
     * Resolves the first argument to a Twitch user, then runs `$then`.
     *
     * Everything here names a person, and naming the wrong one bans the wrong
     * person, so an unresolvable login stops the command rather than being
     * passed on to Helix to interpret.
     *
     * @param callable(string, array<string, mixed>): PromiseInterface $then
     *
     * @return PromiseInterface<string>
     */
    private function onUser(Context $context, Arguments $arguments, callable $then): PromiseInterface
    {
        $broadcaster = $context->requireBroadcaster();
        $raw = (string) $arguments->get(0, '');

        if ($raw === '') {
            throw new ActionError('which user? Give a Twitch username.');
        }

        $login = \TwitchBot\Support\MessageText::normalizeLogin($raw)
            ?? throw new ActionError(sprintf('`%s` is not a valid Twitch username.', $raw));

        return $context->bot->resolveTwitchUser($login)->then(
            static function (?array $user) use ($then, $broadcaster, $login): PromiseInterface {
                if ($user === null) {
                    throw new ActionError(sprintf('Twitch has no user called `%s`.', $login));
                }

                return $then($broadcaster, $user);
            },
        );
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return PromiseInterface<mixed>
     */
    private function settings(Context $context, array $fields): PromiseInterface
    {
        return $context->bot->getTwitch()->chat->updateSettings(
            $context->requireBroadcaster(),
            $this->moderatorId($context),
            $fields,
        );
    }

    /**
     * The bot's own user id.
     *
     * Twitch requires `moderator_id` to be the authenticated user, so this is
     * never the person who typed the command — a distinction worth keeping in
     * mind when reading the audit log, where every action is attributed to the
     * bot account.
     */
    private function moderatorId(Context $context): string
    {
        return $context->bot->getTwitch()->getUserId()
            ?? throw new ActionError('the bot does not know its own Twitch user id yet.');
    }

    private function isOff(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['off', 'false', '0', 'no'], true);
    }
}
