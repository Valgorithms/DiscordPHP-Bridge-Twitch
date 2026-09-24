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

use Bridge\Capability\ProvidesActions;
use Bridge\Command\Access;
use Bridge\Command\Action;
use Bridge\Command\ActionError;
use Bridge\Command\Arguments;
use Bridge\Command\Context;
use Bridge\Command\Slash;
use Bridge\Command\SlashOption;
use Bridge\Support\MessageText;
use Bridge\Twitch\TwitchConnector;
use Bridge\Twitch\TwitchText;
use React\Promise\PromiseInterface;

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
final class ModerationActions implements ProvidesActions
{
    use UsesTwitch;

    /** Twitch caps a timeout at fourteen days. */
    private const MAX_TIMEOUT = 1_209_600;

    public function actions(): array
    {
        return [
            new Action('twitch', 'ban', $this->explained($this->ban(...)), 'Ban someone', '<user> [reason]', access: Access::Moderator, group: 'moderation', slash: new Slash([new SlashOption('user', 'The Twitch username.', SlashOption::STRING, true), new SlashOption('reason', 'Shown in the moderation log.')])),
            new Action('twitch', 'unban', $this->explained($this->unban(...)), 'Lift a ban', '<user>', access: Access::Moderator, group: 'moderation', slash: new Slash([new SlashOption('user', 'The Twitch username.', SlashOption::STRING, true)])),
            new Action('twitch', 'timeout', $this->explained($this->timeout(...)), 'Time someone out', '<user> [seconds] [reason]', access: Access::Moderator, aliases: ['to'], group: 'moderation', slash: new Slash([new SlashOption('user', 'The Twitch username.', SlashOption::STRING, true), new SlashOption('seconds', 'How long, in seconds. Defaults to 600.', SlashOption::INTEGER), new SlashOption('reason', 'Shown in the moderation log.')])),
            new Action('twitch', 'vip', $this->explained($this->vip(...)), 'Give VIP', '<user>', access: Access::Administrator, group: 'moderation', slash: new Slash([new SlashOption('user', 'The Twitch username.', SlashOption::STRING, true)])),
            new Action('twitch', 'unvip', $this->explained($this->unvip(...)), 'Take VIP away', '<user>', access: Access::Administrator, group: 'moderation', slash: new Slash([new SlashOption('user', 'The Twitch username.', SlashOption::STRING, true)])),
            new Action('twitch', 'mod', $this->explained($this->mod(...)), 'Give moderator', '<user>', access: Access::Administrator, group: 'moderation', slash: new Slash([new SlashOption('user', 'The Twitch username.', SlashOption::STRING, true)])),
            new Action('twitch', 'unmod', $this->explained($this->unmod(...)), 'Take moderator away', '<user>', access: Access::Administrator, group: 'moderation', slash: new Slash([new SlashOption('user', 'The Twitch username.', SlashOption::STRING, true)])),
            new Action('twitch', 'clear', $this->explained($this->clear(...)), 'Clear the chat', access: Access::Moderator, group: 'moderation', slash: new Slash()),
            new Action('twitch', 'announce', $this->explained($this->announce(...)), 'Post a highlighted announcement', '<message>', access: Access::Moderator, group: 'moderation', slash: new Slash([new SlashOption('message', 'The announcement text.', SlashOption::STRING, true)])),
            new Action('twitch', 'shoutout', $this->explained($this->shoutout(...)), 'Shout out another channel', '<channel>', access: Access::Moderator, aliases: ['so'], cooldown: 120, group: 'moderation', slash: new Slash([new SlashOption('channel', 'The Twitch channel to shout out.', SlashOption::STRING, true)])),
            new Action('twitch', 'slow', $this->explained($this->slow(...)), 'Slow mode on (seconds) or off', '[seconds|off]', access: Access::Moderator, group: 'moderation', slash: new Slash([new SlashOption('seconds', 'Seconds between messages, or "off".')])),
            new Action('twitch', 'subonly', $this->explained($this->subonly(...)), 'Subscriber-only chat on or off', '[on|off]', access: Access::Moderator, group: 'moderation', slash: new Slash([new SlashOption('state', '"on" or "off". Defaults to on.')])),
            new Action('twitch', 'emoteonly', $this->explained($this->emoteonly(...)), 'Emote-only chat on or off', '[on|off]', access: Access::Moderator, group: 'moderation', slash: new Slash([new SlashOption('state', '"on" or "off". Defaults to on.')])),
            // No `followers` alias: that name belongs to the read-only
            // follower count in StreamActions, and the registry rejects the
            // clash rather than letting one silently shadow the other.
            new Action('twitch', 'followersonly', $this->explained($this->followersonly(...)), 'Followers-only chat, with an optional minutes threshold', '[minutes|off]', access: Access::Moderator, aliases: ['followermode'], group: 'moderation', slash: new Slash([new SlashOption('minutes', 'Minimum follow age in minutes, or "off".')])),
            new Action('twitch', 'raid', $this->explained($this->raid(...)), 'Raid another channel', '<channel>', access: Access::Administrator, group: 'cast', slash: new Slash([new SlashOption('channel', 'The Twitch channel to raid.', SlashOption::STRING, true)])),
            new Action('twitch', 'unraid', $this->explained($this->unraid(...)), 'Cancel a pending raid', access: Access::Administrator, group: 'cast', slash: new Slash()),
            new Action('twitch', 'commercial', $this->explained($this->commercial(...)), 'Start an ad break', '[seconds]', access: Access::Administrator, group: 'cast', slash: new Slash([new SlashOption('seconds', 'Ad break length in seconds. Defaults to 60.', SlashOption::INTEGER)])),
        ];
    }

    // ── Bans and roles ─────────────────────────────────────────────────

    /** @return PromiseInterface<string> */
    private function ban(Context $context, Arguments $arguments): PromiseInterface
    {
        $reason = $this->reason($arguments, 1);

        return $this->onUser($context, $arguments, fn (string $broadcaster, array $user) =>
            $this->twitch($context)->getTwitch()->moderation
                ->ban($broadcaster, $this->moderatorId($context), $user['id'], $reason !== '' ? $reason : null)
                ->then(static fn (): string => sprintf('banned %s.', $user['display_name'])));
    }

    /** @return PromiseInterface<string> */
    private function unban(Context $context, Arguments $arguments): PromiseInterface
    {
        return $this->onUser($context, $arguments, fn (string $broadcaster, array $user) =>
            $this->twitch($context)->getTwitch()->moderation
                ->unban($broadcaster, $this->moderatorId($context), $user['id'])
                ->then(static fn (): string => sprintf('unbanned %s.', $user['display_name'])));
    }

    /** @return PromiseInterface<string> */
    private function timeout(Context $context, Arguments $arguments): PromiseInterface
    {
        $seconds = 600;
        $reasonFrom = 1;

        // `timeout bob 300 spam` and `timeout bob spam` both have to work, so
        // the duration is only consumed when it actually looks like one. A
        // slash command supplies it by name, where there is no ambiguity.
        $second = $arguments->named('seconds') ?? (string) $arguments->get(1, '');

        if ($second !== '' && preg_match('/^\d+$/', $second) === 1) {
            $seconds = (int) $second;
            $reasonFrom = 2;
        }

        if ($seconds < 1 || $seconds > self::MAX_TIMEOUT) {
            throw new ActionError(sprintf('a timeout has to be between 1 second and 14 days (%d seconds).', self::MAX_TIMEOUT));
        }

        $reason = $this->reason($arguments, $reasonFrom);

        return $this->onUser($context, $arguments, fn (string $broadcaster, array $user) =>
            $this->twitch($context)->getTwitch()->moderation
                ->timeout($broadcaster, $this->moderatorId($context), $user['id'], $seconds, $reason !== '' ? $reason : null)
                ->then(static fn (): string => sprintf('timed %s out for %ds.', $user['display_name'], $seconds)));
    }

    /** @return PromiseInterface<string> */
    private function vip(Context $context, Arguments $arguments): PromiseInterface
    {
        return $this->onUser($context, $arguments, fn (string $broadcaster, array $user) =>
            $this->twitch($context)->getTwitch()->moderation->addVip($broadcaster, $user['id'])
                ->then(static fn (): string => sprintf('%s is now a VIP.', $user['display_name'])));
    }

    /** @return PromiseInterface<string> */
    private function unvip(Context $context, Arguments $arguments): PromiseInterface
    {
        return $this->onUser($context, $arguments, fn (string $broadcaster, array $user) =>
            $this->twitch($context)->getTwitch()->moderation->removeVip($broadcaster, $user['id'])
                ->then(static fn (): string => sprintf('%s is no longer a VIP.', $user['display_name'])));
    }

    /** @return PromiseInterface<string> */
    private function mod(Context $context, Arguments $arguments): PromiseInterface
    {
        return $this->onUser($context, $arguments, fn (string $broadcaster, array $user) =>
            $this->twitch($context)->getTwitch()->moderation->addModerator($broadcaster, $user['id'])
                ->then(static fn (): string => sprintf('%s is now a moderator.', $user['display_name'])));
    }

    /** @return PromiseInterface<string> */
    private function unmod(Context $context, Arguments $arguments): PromiseInterface
    {
        return $this->onUser($context, $arguments, fn (string $broadcaster, array $user) =>
            $this->twitch($context)->getTwitch()->moderation->removeModerator($broadcaster, $user['id'])
                ->then(static fn (): string => sprintf('%s is no longer a moderator.', $user['display_name'])));
    }

    // ── Chat ───────────────────────────────────────────────────────────

    /** @return PromiseInterface<string> */
    private function clear(Context $context, Arguments $arguments): PromiseInterface
    {
        return $this->twitch($context)->getTwitch()->moderation
            ->deleteMessages($context->requireTarget(), $this->moderatorId($context))
            ->then(static fn (): string => 'chat cleared.');
    }

    /** @return PromiseInterface<string> */
    private function announce(Context $context, Arguments $arguments): PromiseInterface
    {
        $text = $arguments->rest();

        if ($text === '') {
            throw new ActionError('announce what? `announce <message>`');
        }

        return $this->twitch($context)->getTwitch()->chat
            ->announce($context->requireTarget(), $this->moderatorId($context), self::attributed($context, $text))
            ->then(static fn (): string => 'announced.');
    }

    /**
     * An announcement's text, saying who sent it and from where when that was
     * not Twitch.
     *
     * Twitch shows every announcement as the host's. Typed in Twitch chat, the
     * command that made it is right above it; typed in Discord or Telegram,
     * nothing would say it came from someone else, so it says so the way
     * relayed chat does. The name is defused the same way too (F2).
     */
    private static function attributed(Context $context, string $text): string
    {
        if ($context->surface->name === TwitchConnector::NAME) {
            return $text;
        }

        return MessageText::truncate(
            sprintf('%s (%s): %s', TwitchText::speaker($context->invokerName), $context->surface->name, $text),
            TwitchText::LIMIT,
        );
    }

    /** @return PromiseInterface<string> */
    private function shoutout(Context $context, Arguments $arguments): PromiseInterface
    {
        return $this->onUser($context, $arguments, fn (string $broadcaster, array $user) =>
            $this->twitch($context)->getTwitch()->chat
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
            $this->twitch($context)->getTwitch()->raids->start($broadcaster, $user['id'])
                ->then(static fn (): string => sprintf('raiding %s — it starts in 90 seconds.', $user['display_name'])));
    }

    /** @return PromiseInterface<string> */
    private function unraid(Context $context, Arguments $arguments): PromiseInterface
    {
        return $this->twitch($context)->getTwitch()->raids->cancel($context->requireTarget())
            ->then(static fn (): string => 'raid cancelled.');
    }

    /** @return PromiseInterface<string> */
    private function commercial(Context $context, Arguments $arguments): PromiseInterface
    {
        $seconds = (int) ($arguments->get(0, '60') ?? '60');

        if ($seconds < 1 || $seconds > 180) {
            throw new ActionError('an ad break has to be between 1 and 180 seconds.');
        }

        return $this->twitch($context)->getTwitch()->ads->startCommercial($context->requireTarget(), $seconds)
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
        $broadcaster = $context->requireTarget();
        $raw = (string) $arguments->get(0, '');

        if ($raw === '') {
            throw new ActionError('which user? Give a Twitch username.');
        }

        $login = \Bridge\Twitch\TwitchText::normalizeLogin($raw)
            ?? throw new ActionError(sprintf('`%s` is not a valid Twitch username.', $raw));

        return $this->twitch($context)->lookupUser($login)->then(
            static function (?array $user) use ($then, $broadcaster, $login): PromiseInterface {
                if ($user === null) {
                    throw new ActionError(sprintf('Twitch has no user called `%s`.', $login));
                }

                // Some accounts have no display name; the reply still has to
                // say who was acted on.
                $user['display_name'] = $user['display_name'] !== '' ? $user['display_name'] : $user['login'];

                return $then($broadcaster, $user);
            },
            // Checked before the `then` above can run, so a moderation call is
            // never made against a user who could not be looked up.
            static fn (\Throwable $e): never => throw new ActionError(sprintf(
                'could not look `%s` up on Twitch just now — try again in a moment.',
                $login,
            ), 0, $e),
        );
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return PromiseInterface<mixed>
     */
    private function settings(Context $context, array $fields): PromiseInterface
    {
        return $this->twitch($context)->getTwitch()->chat->updateSettings(
            $context->requireTarget(),
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
        return $this->twitch($context)->getTwitch()->getUserId()
            ?? throw new ActionError('the bot does not know its own Twitch user id yet.');
    }

    /**
     * The reason for a moderation action, from whichever form supplied it.
     *
     * The named value wins. A slash command omits an unsupplied optional
     * entirely, so `/timeout user:bob reason:spam` — no `seconds` — leaves a
     * gap that stops positional recording at `bob`; reading positionally alone
     * would silently drop the reason and log the ban as unexplained.
     */
    private function reason(Arguments $arguments, int $from): string
    {
        return $arguments->named('reason') ?? $arguments->rest($from);
    }

    private function isOff(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['off', 'false', '0', 'no'], true);
    }
}
