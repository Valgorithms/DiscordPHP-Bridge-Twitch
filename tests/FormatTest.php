<?php

declare(strict_types=1);

namespace TwitchBot\Tests;

use PHPUnit\Framework\TestCase;
use TwitchBot\Command\Surface;
use TwitchBot\Support\Format;

/**
 * One answer, two chats.
 *
 * A Discord channel takes 2000 characters and renders Markdown; a Twitch one
 * takes 500 and renders none. These helpers are what let an action produce a
 * single result without branching on the surface itself.
 */
final class FormatTest extends TestCase
{
    public function testTwitchFieldsAreASingleLine(): void
    {
        $rendered = Format::fields(Surface::Twitch, ['viewers' => 12, 'uptime' => '3h 14m']);

        self::assertStringNotContainsString("\n", $rendered);
        self::assertStringContainsString('viewers: 12', $rendered);
        self::assertStringContainsString('uptime: 3h 14m', $rendered);
    }

    public function testDiscordFieldsAreFenced(): void
    {
        $rendered = Format::fields(Surface::Discord, ['viewers' => 12], 'Live');

        self::assertStringContainsString('```', $rendered);
        self::assertStringContainsString('**Live**', $rendered);
    }

    public function testEmptyValuesAreDropped(): void
    {
        $rendered = Format::fields(Surface::Twitch, ['title' => 'x', 'delay' => null, 'tags' => '']);

        self::assertStringContainsString('title: x', $rendered);
        self::assertStringNotContainsString('delay', $rendered);
        self::assertStringNotContainsString('tags', $rendered);
    }

    public function testBooleansRenderAsWords(): void
    {
        self::assertStringContainsString('mature: yes', Format::fields(Surface::Twitch, ['mature' => true]));
        self::assertStringContainsString('mature: no', Format::fields(Surface::Twitch, ['mature' => false]));
    }

    public function testAllEmptyFieldsSaysSo(): void
    {
        self::assertStringContainsString('nothing to show', Format::fields(Surface::Twitch, ['a' => null]));
    }

    public function testTwitchListingIsCommaJoined(): void
    {
        $rendered = Format::listing(Surface::Twitch, ['one', 'two'], 'things');

        self::assertSame('things: one, two', $rendered);
    }

    public function testDiscordListingIsNumbered(): void
    {
        $rendered = Format::listing(Surface::Discord, ['one', 'two'], 'things');

        self::assertStringContainsString('1. one', $rendered);
        self::assertStringContainsString('2. two', $rendered);
    }

    public function testEmptyListingUsesTheGivenWording(): void
    {
        self::assertStringContainsString('none yet', Format::listing(Surface::Discord, [], 'things', 'none yet'));
    }

    /** API output must not be able to break out of the code fence. */
    public function testCodeFencesInPayloadAreNeutralised(): void
    {
        $rendered = Format::code(Surface::Discord, 'before ``` after');
        $inner = substr($rendered, 4, -4);

        self::assertStringNotContainsString('```', $inner);
    }

    public function testTwitchCodeIsPlainAndClamped(): void
    {
        $rendered = Format::code(Surface::Twitch, str_repeat('x', 900));

        self::assertStringNotContainsString('```', $rendered);
        self::assertLessThanOrEqual(500, mb_strlen($rendered));
    }

    public function testDiscordCodeStaysWithinTheLimit(): void
    {
        self::assertLessThanOrEqual(2000, mb_strlen(Format::code(Surface::Discord, str_repeat('y', 5000))));
    }

    public function testDurations(): void
    {
        self::assertSame('just now', Format::duration(0));
        self::assertSame('just now', Format::duration(59));
        self::assertSame('1m', Format::duration(60));
        self::assertSame('3h 14m', Format::duration(3 * 3600 + 14 * 60));
        // Only the two largest units, so it stays readable.
        self::assertSame('2d 3h', Format::duration(2 * 86400 + 3 * 3600 + 30 * 60));
    }

    public function testNumbers(): void
    {
        self::assertSame('42', Format::number(42));
        self::assertSame('999', Format::number(999));
        self::assertSame('1k', Format::number(1000));
        self::assertSame('1.5k', Format::number(1500));
        self::assertSame('2.4M', Format::number(2_400_000));
    }

    public function testClampRespectsEachSurface(): void
    {
        self::assertLessThanOrEqual(500, mb_strlen(Format::clamp(str_repeat('a', 900), Surface::Twitch)));
        self::assertLessThanOrEqual(2000, mb_strlen(Format::clamp(str_repeat('a', 5000), Surface::Discord)));
        self::assertSame('short', Format::clamp('short', Surface::Twitch));
    }

    public function testSurfaceLimits(): void
    {
        self::assertSame(500, Surface::Twitch->limit());
        self::assertSame(2000, Surface::Discord->limit());
        self::assertTrue(Surface::Discord->supportsMarkdown());
        self::assertFalse(Surface::Twitch->supportsMarkdown());
    }
}
