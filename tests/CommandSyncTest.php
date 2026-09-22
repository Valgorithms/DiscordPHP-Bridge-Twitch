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

namespace TwitchBot\Tests;

use PHPUnit\Framework\TestCase;
use TwitchBot\Support\CommandSync;

/**
 * A restart must publish a command whose definition changed in code, and must
 * not republish one that only *looks* different because Discord echoed it back
 * with its own fields and defaults.
 *
 * Both halves matter here. This bot defines 33 slash commands, so republishing
 * all of them every boot is 33 rate-limited writes to say nothing; and a bot
 * that instead skips whatever is already registered would leave a command
 * frozen in the shape it had the first time it was published.
 */
final class CommandSyncTest extends TestCase
{
    public function testAnUnchangedCommandIsNotRepublished(): void
    {
        $this->assertFalse(CommandSync::differs($this->published(), $this->built()));
    }

    public function testDiscordsOwnFieldsAreIgnored(): void
    {
        $published = $this->published() + [
            'id' => '123456789',
            'application_id' => '987654321',
            'version' => '111',
            'dm_permission' => true,
            'guild_id' => null,
        ];

        $this->assertFalse(CommandSync::differs($published, $this->built()));
    }

    public function testKeyOrderIsNotAChange(): void
    {
        $this->assertFalse(CommandSync::differs(array_reverse($this->published(), true), $this->built()));
    }

    public function testAnOmittedFalseIsTheSameAsAnExplicitOne(): void
    {
        // Discord drops a false `required` rather than sending it back.
        $published = $this->published();
        unset($published['options'][1]['options'][1]['required']);
        unset($published['nsfw']);

        $this->assertFalse(CommandSync::differs($published, $this->built()));
    }

    public function testAnOmittedTypeMeansChatInput(): void
    {
        $published = $this->published();
        unset($published['type']);

        $this->assertFalse(CommandSync::differs($published, $this->built()));
    }

    public function testContextsAndIntegrationTypesAreSets(): void
    {
        $built = $this->built();
        $built['contexts'] = [0];
        $built['integration_types'] = [0];

        $published = $this->published();
        $published['contexts'] = [0];
        $published['integration_types'] = [0];

        $this->assertFalse(CommandSync::differs($published, $built));

        // Same members, different order: still the same command.
        $published['contexts'] = [1, 0];
        $built['contexts'] = [0, 1];

        $this->assertFalse(CommandSync::differs($published, $built));
    }

    public function testANewSubCommandIsAChange(): void
    {
        // The case the whole class exists for: `relay reset` added in code,
        // never offered by Discord unless this returns true.
        $built = $this->built();
        $built['options'][] = ['type' => 1, 'name' => 'reset', 'description' => 'Clear every relay on this server.'];

        $this->assertTrue(CommandSync::differs($this->published(), $built));
    }

    public function testARewordedDescriptionIsAChange(): void
    {
        $built = $this->built();
        $built['description'] = 'Configure the Twitch relay. Manage Server only.';

        $this->assertTrue(CommandSync::differs($this->published(), $built));
    }

    public function testAnOptionBecomingRequiredIsAChange(): void
    {
        $built = $this->built();
        $built['options'][1]['options'][1]['required'] = true;

        $this->assertTrue(CommandSync::differs($this->published(), $built));
    }

    public function testReorderedSubCommandsAreAChange(): void
    {
        // Sub-commands are shown in the order they were declared, so order is
        // part of the definition, unlike contexts.
        $built = $this->built();
        $built['options'] = array_reverse($built['options']);

        $this->assertTrue(CommandSync::differs($this->published(), $built));
    }

    public function testObjectShapedPayloadsAreHandled(): void
    {
        // A Part or a raw gateway payload arrives as objects, not arrays.
        $published = json_decode((string) json_encode($this->published()));

        $this->assertFalse(CommandSync::differs((array) $published, $this->built()));
    }

    /** What Discord has: the same command, echoed back with its own fields. */
    private function published(): array
    {
        return $this->built() + ['id' => '1', 'application_id' => '2', 'version' => '3'];
    }

    /** What this build defines: `relay`, as {@see \TwitchBot\Actions\RelayActions} declares it. */
    private function built(): array
    {
        return [
            'type' => 1,
            'name' => 'relay',
            'description' => 'Configure the Twitch chat relay for this server.',
            'contexts' => [0],
            'integration_types' => [0],
            'nsfw' => false,
            'options' => [
                ['type' => 1, 'name' => 'list', 'description' => 'Show every relay configured on this server.'],
                [
                    'type' => 1,
                    'name' => 'link',
                    'description' => 'Relay a Discord channel with a Twitch channel.',
                    'options' => [
                        ['type' => 3, 'name' => 'twitch', 'description' => 'The Twitch channel to follow.', 'required' => true],
                        ['type' => 7, 'name' => 'channel', 'description' => 'The Discord channel to relay.', 'required' => false],
                    ],
                ],
            ],
        ];
    }
}
