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

namespace Bridge\Twitch\Tests;

use Bridge\Command\Access;
use Bridge\Command\Action;
use Bridge\Command\ActionRegistry;
use Bridge\Command\Slash;
use Bridge\Twitch\Actions\ApiActions;
use Bridge\Twitch\Actions\ChannelActions;
use Bridge\Twitch\Actions\ModerationActions;
use Bridge\Twitch\Actions\StreamActions;
use Bridge\Twitch\TwitchConnector;
use PHPUnit\Framework\TestCase;

/**
 * What `/twitch` actually becomes.
 *
 * Discord caps a command at 25 options and allows one level of sub-command
 * group, and it rejects the *whole command* when either is exceeded — so
 * overflowing here does not lose one command, it loses all of them. Counting it
 * is cheaper than finding out.
 */
final class CommandTreeTest extends TestCase
{
    /** Discord's cap on options, sub-commands and groups alike. */
    private const MAX_OPTIONS = 25;

    public function testEveryActionIsQualifiedTwitch(): void
    {
        foreach ($this->actions() as $action) {
            $this->assertSame(
                TwitchConnector::NAME,
                $action->qualifier,
                $action->qualified() . ' is not under the connector that owns it',
            );
        }
    }

    public function testTheTreeFitsInsideDiscordsCaps(): void
    {
        $registry = new ActionRegistry();

        foreach ($this->actions() as $action) {
            $registry->add($action);
        }

        // The six bridge verbs the core adds for every connector sit at the
        // top level too, so they count.
        $topLevel = 6;
        $groups = [];

        foreach ($registry->all() as $action) {
            if ($action->group === null) {
                ++$topLevel;

                continue;
            }

            $groups[$action->group][] = $action->name;
        }

        $this->assertLessThanOrEqual(
            self::MAX_OPTIONS,
            $topLevel + count($groups),
            '/twitch declares too many top-level options',
        );

        foreach ($groups as $group => $names) {
            $this->assertLessThanOrEqual(
                self::MAX_OPTIONS,
                count($names),
                "/twitch {$group} declares too many sub-commands",
            );
        }
    }

    public function testNoActionRepeatsItsOwnGroup(): void
    {
        // `/twitch channel channel` reads like a stutter and tells a reader
        // nothing the group has not already said.
        foreach ($this->actions() as $action) {
            $this->assertNotSame($action->group, $action->name, $action->qualified() . ' repeats its group');
        }
    }

    public function testNothingCollidesWithTheBridgeVerbsTheCoreAdds(): void
    {
        // A connector action called `status` would collide with the `status`
        // every connector gets, and the registry would refuse the boot.
        $reserved = ['link', 'here', 'unlink', 'list', 'status', 'reset'];

        foreach ($this->actions() as $action) {
            $this->assertNotContains($action->name, $reserved, $action->qualified() . ' is a reserved bridge verb');

            foreach ($action->aliases as $alias) {
                $this->assertNotContains($alias, $reserved, $action->qualified() . " aliases a reserved verb: {$alias}");
            }
        }
    }

    public function testEveryActionIsReachableAsASlashCommand(): void
    {
        // Message Content is privileged: a server that has not granted it gets
        // no prefix commands at all, and slash commands are all it has left.
        foreach ($this->actions() as $action) {
            $this->assertInstanceOf(Slash::class, $action->slash, $action->qualified() . ' has no slash form');
        }
    }

    public function testEveryActionWorksInTwitchChatToo(): void
    {
        $surface = (new TwitchConnector($this->config()))->surface();

        foreach ($this->actions() as $action) {
            $this->assertTrue($action->availableOn($surface), $action->qualified() . ' is unavailable in chat');
        }
    }

    public function testTheDangerousOnesAreGated(): void
    {
        $expected = [
            'twitch ban' => Access::Moderator,
            'twitch timeout' => Access::Moderator,
            'twitch clear' => Access::Moderator,
            'twitch raid' => Access::Administrator,
            'twitch commercial' => Access::Administrator,
            // `api` reaches endpoints that ban users and end streams, using
            // the bot's own token.
            'twitch api' => Access::Operator,
        ];

        $actions = [];

        foreach ($this->actions() as $action) {
            $actions[$action->key()] = $action->access;
        }

        foreach ($expected as $key => $access) {
            $this->assertSame($access, $actions[$key] ?? null, $key . ' is not gated as it should be');
        }
    }

    /** @return list<Action> */
    private function actions(): array
    {
        return [
            ...(new ChannelActions())->actions(),
            ...(new StreamActions())->actions(),
            ...(new ModerationActions())->actions(),
            ...(new ApiActions())->actions(),
        ];
    }

    private function config(): \Bridge\Twitch\TwitchConfig
    {
        return \Bridge\Twitch\TwitchConfig::fromEnvironment(
            \Bridge\Environment::fromArray(['TWITCH_CLIENT_ID' => 'cid', 'TWITCH_NICK' => 'bot']),
        );
    }
}
