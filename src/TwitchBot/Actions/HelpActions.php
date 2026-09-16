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

use TwitchBot\Bot;
use TwitchBot\Command\Action;
use TwitchBot\Command\ActionProvider;
use TwitchBot\Command\Arguments;
use TwitchBot\Command\Context;
use TwitchBot\Command\Surface;
use TwitchBot\Support\Format;

/**
 * `help` and `about`.
 *
 * Replaces both clients' built-in help. Theirs are fine, but there are two of
 * them, worded differently, and neither knows that a command might be hidden on
 * the other surface or above the asker's permission level.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class HelpActions implements ActionProvider
{
    public function actions(): array
    {
        return [
            new Action(
                'help',
                $this->help(...),
                'List the commands you can use, or explain one',
                '[command]',
                aliases: ['commands'],
                cooldown: 5,
                group: 'general',
            ),
            new Action(
                'about',
                static fn (Context $context): string => sprintf(
                    'Discord ↔ Twitch bot, %d commands, bridging %d channel(s). %s',
                    count($context->bot->getActions()->forSurface($context->surface)),
                    $context->bot->getStore()->links()->count(),
                    Bot::GITHUB,
                ),
                'What this bot is',
                cooldown: 30,
                group: 'general',
            ),
        ];
    }

    private function help(Context $context, Arguments $arguments): string
    {
        $prefix = $this->prefix($context);
        $registry = $context->bot->getActions();

        if (! $arguments->isEmpty()) {
            $name = (string) $arguments->get(0, '');
            $action = $registry->get($name);

            if ($action === null || ! $action->availableOn($context->surface)) {
                return sprintf('there is no `%s` command here. Try `%shelp`.', $name, $prefix);
            }

            if (! $context->access->satisfies($action->access)) {
                return sprintf('`%s%s` is limited to %s.', $prefix, $action->name, $action->access->label());
            }

            $detail = $action->help($prefix);

            if ($action->aliases !== []) {
                $detail .= ' (also: ' . implode(', ', array_map(
                    static fn (string $a): string => $prefix . $a,
                    $action->aliases,
                )) . ')';
            }

            return $detail;
        }

        $groups = $registry->grouped($context->surface, $context->access);

        if ($groups === []) {
            return 'no commands are available to you here.';
        }

        // Twitch gets one line, because five would be five messages against a
        // twenty-per-thirty-seconds budget. Discord gets the grouped listing.
        if ($context->surface === Surface::Twitch) {
            $names = [];
            foreach ($groups as $actions) {
                foreach ($actions as $action) {
                    $names[] = $prefix . $action->name;
                }
            }

            return Format::listing($context->surface, $names, 'commands');
        }

        $lines = [];
        foreach ($groups as $group => $actions) {
            $lines[] = '**' . ucfirst($group) . '**';

            foreach ($actions as $action) {
                $lines[] = '· `' . $prefix . $action->name
                    . ($action->usage !== '' ? ' ' . $action->usage : '') . '` — ' . $action->description;
            }

            $lines[] = '';
        }

        $lines[] = sprintf('_`%shelp <command>` for detail._', $prefix);

        return Format::clamp(implode("\n", $lines), $context->surface);
    }

    private function prefix(Context $context): string
    {
        $config = $context->bot->getConfig();

        return $context->surface === Surface::Twitch ? $config->twitchPrefix : $config->discordPrefix;
    }
}
