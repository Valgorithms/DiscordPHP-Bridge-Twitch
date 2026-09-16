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

use Discord\Parts\Channel\Message;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

use TwitchBot\Command\Access;
use TwitchBot\Command\Action;
use TwitchBot\Command\ActionError;
use TwitchBot\Command\ActionProvider;
use TwitchBot\Command\Arguments;
use TwitchBot\Command\Context;
use TwitchBot\Command\Surface;
use TwitchBot\Support\Format;
use TwitchBot\Support\MessageText;

/**
 * Configuring the chat relay — the bot's primary feature.
 *
 * Split in two on purpose. `relay` mutates the routing table and lives on
 * Discord only, because every one of its operations needs a Discord channel to
 * point at and there is no way to name one from Twitch chat. `bridge` is
 * read-only, answers on both surfaces, and is open to everyone, so a viewer can
 * see where a channel is wired without being able to rewire it.
 *
 * The permission gate on `relay` is the security model for the whole project:
 * whoever can run `relay link` decides which Discord channel gets copied into a
 * public Twitch chat. Point it at a private channel and that channel is on
 * stream.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class RelayActions implements ActionProvider
{
    public function actions(): array
    {
        return [
            new Action(
                'relay',
                $this->relay(...),
                'Configure the Discord ↔ Twitch chat relay',
                'link <twitch-channel> [#discord-channel] | unlink [#channel] | list | reset',
                access: Access::Broadcaster,
                aliases: ['config'],
                only: Surface::Discord,
                group: 'relay',
            ),
            new Action(
                'bridge',
                $this->bridge(...),
                'Show where this channel relays to',
                cooldown: 15,
                group: 'relay',
            ),
        ];
    }

    private function relay(Context $context, Arguments $arguments): PromiseInterface|string
    {
        return match (strtolower((string) $arguments->get(0, ''))) {
            'link', 'set', 'add' => $this->link($context, $arguments),
            'unlink', 'unset', 'remove' => $this->unlink($context, $arguments),
            'list', 'view', 'show' => $this->list($context),
            'reset', 'clear' => $this->reset($context),
            default => 'usage: `relay link <twitch-channel> [#discord-channel]`, `relay unlink [#channel]`, `relay list`, `relay reset`.',
        };
    }

    /** @return PromiseInterface<string> */
    private function link(Context $context, Arguments $arguments): PromiseInterface
    {
        $message = $this->message($context);
        $guildId = (string) ($message->guild_id ?? '');

        if ($guildId === '') {
            throw new ActionError('the relay can only be configured inside a server.');
        }

        $raw = (string) $arguments->get(1, '');

        if ($raw === '') {
            throw new ActionError('which Twitch channel? `relay link <twitch-channel> [#discord-channel]`');
        }

        $login = MessageText::normalizeLogin($raw)
            ?? throw new ActionError(sprintf('`%s` is not a valid Twitch channel name.', $raw));

        $channelId = $this->targetChannel($context, $arguments, 2);

        // Check the channel exists before wiring it up. A typo would otherwise
        // produce a bridge that silently never works — the bot would join a
        // channel that isn't there and simply never hear anything.
        return $context->bot->resolveTwitchUser($login)->then(
            function (?array $user) use ($context, $guildId, $channelId, $login): string {
                if ($user === null) {
                    throw new ActionError(sprintf('Twitch has no channel called `%s`.', $login));
                }

                $links = $context->bot->getStore()->link($guildId, $channelId, $user['login']);
                $context->bot->syncTwitchChannels($links);

                return sprintf(
                    '<#%s> is now relaying with **%s** (https://twitch.tv/%s). Messages here go to that chat, and its chat comes back here.',
                    $channelId,
                    $user['display_name'],
                    $user['login'],
                );
            },
        );
    }

    private function unlink(Context $context, Arguments $arguments): string
    {
        $message = $this->message($context);
        $guildId = (string) ($message->guild_id ?? '');

        if ($guildId === '') {
            throw new ActionError('the relay can only be configured inside a server.');
        }

        $channelId = $this->targetChannel($context, $arguments, 1);
        $before = $context->bot->getStore()->links()->twitchFor($channelId);

        if ($before === null) {
            return sprintf('<#%s> was not relaying anywhere.', $channelId);
        }

        $links = $context->bot->getStore()->unlink($guildId, $channelId);
        $context->bot->syncTwitchChannels($links);

        return sprintf('<#%s> is no longer relaying with **%s**.', $channelId, $before);
    }

    private function list(Context $context): string
    {
        $message = $this->message($context);
        $guildId = (string) ($message->guild_id ?? '');
        $links = $context->bot->getStore()->links()->forGuild($guildId);

        $items = [];
        foreach ($links as $channelId => $login) {
            $items[] = sprintf('<#%s> ↔ **%s**', $channelId, $login);
        }

        return Format::listing(
            $context->surface,
            $items,
            'Relays in this server',
            'none yet — `relay link <twitch-channel>` sets one up.',
        );
    }

    private function reset(Context $context): string
    {
        $message = $this->message($context);
        $guildId = (string) ($message->guild_id ?? '');
        $count = count($context->bot->getStore()->links()->forGuild($guildId));

        if ($count === 0) {
            return 'there was nothing to clear.';
        }

        $links = $context->bot->getStore()->forgetGuild($guildId);
        $context->bot->syncTwitchChannels($links);

        return sprintf('cleared %d relay%s in this server.', $count, $count === 1 ? '' : 's');
    }

    /** @return PromiseInterface<string>|string */
    private function bridge(Context $context, Arguments $arguments): PromiseInterface|string
    {
        if ($context->surface === Surface::Twitch) {
            $count = count($context->bot->getStore()->links()->discordFor((string) $context->broadcasterLogin));

            return $count === 0
                ? 'this chat is not relaying to Discord.'
                : sprintf('this chat is relaying to %d Discord channel%s.', $count, $count === 1 ? '' : 's');
        }

        $message = $this->message($context);
        $login = $context->bot->getStore()->links()->twitchFor((string) $message->channel_id);

        return resolve($login === null
            ? 'this channel is not relaying to Twitch. An admin can set that up with `relay link <twitch-channel>`.'
            : sprintf('this channel is relaying with **%s** (https://twitch.tv/%s).', $login, $login));
    }

    /**
     * The Discord channel an operation applies to: an explicit `<#id>` mention,
     * otherwise the channel the command was typed in.
     */
    private function targetChannel(Context $context, Arguments $arguments, int $index): string
    {
        $explicit = (string) $arguments->get($index, '');

        if ($explicit !== '' && preg_match('/^<#(\d+)>$/', $explicit, $matches) === 1) {
            return $matches[1];
        }

        if ($explicit !== '' && preg_match('/^\d{5,}$/', $explicit) === 1) {
            return $explicit;
        }

        if ($explicit !== '') {
            throw new ActionError(sprintf('`%s` is not a channel — mention one like #general.', $explicit));
        }

        return (string) $this->message($context)->channel_id;
    }

    private function message(Context $context): Message
    {
        $message = $context->message;

        if (! $message instanceof Message) {
            throw new ActionError('that command only works from Discord.');
        }

        return $message;
    }
}
