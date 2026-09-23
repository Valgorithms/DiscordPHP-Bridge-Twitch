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

use Bridge\Message\Media;
use Bridge\Message\Outgoing;
use Bridge\Twitch\TwitchText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What IRC and Twitch specifically demand of a line of text.
 *
 * The shared half — resolving mentions, budgeting links, truncating — is the
 * core's and is tested there. What is here is the half where getting it wrong
 * hands somebody the bot account.
 */
final class TwitchTextTest extends TestCase
{
    // ── Staying inside the PRIVMSG ─────────────────────────────────────

    public function testCarriageReturnsCannotInjectAnIrcCommand(): void
    {
        // `Irc::say()` interpolates into `PRIVMSG #chan :<text>\r\n`. A CR or
        // LF here ends that line and the rest is parsed as a fresh command.
        $injected = "hello\r\nPRIVMSG #victim :spam";

        $this->assertStringNotContainsString("\r", TwitchText::sanitizeIrc($injected));
        $this->assertStringNotContainsString("\n", TwitchText::sanitizeIrc($injected));
    }

    public function testControlCharactersAreStripped(): void
    {
        $this->assertSame('hello', TwitchText::sanitizeIrc("he\x00l\x07lo"));
    }

    public function testNewlinesBecomeSpacesRatherThanVanishing(): void
    {
        // Otherwise a multi-line message arrives with its words run together.
        $this->assertSame('one two', TwitchText::sanitizeIrc("one\ntwo"));
    }

    // ── Not becoming a chat command ────────────────────────────────────

    #[DataProvider('commandLeaders')]
    public function testAReplyCannotBeExecutedAsAChatCommand(string $text): void
    {
        // Twitch reads a leading `/` or `.` as a command, so an API error that
        // quotes a path would be run rather than said.
        $safe = TwitchText::safeLine($text);

        $this->assertNotSame('/', $safe[0] ?? '');
        $this->assertNotSame('.', $safe[0] ?? '');
    }

    /** @return iterable<string, array{string}> */
    public static function commandLeaders(): iterable
    {
        yield 'slash' => ['/ban someone'];
        yield 'dot' => ['.mods'];
        yield 'error quoting a path' => ['/helix/channels returned 401'];
    }

    public function testSafeLineStaysWithinTheLimit(): void
    {
        $this->assertLessThanOrEqual(TwitchText::LIMIT, mb_strlen(TwitchText::safeLine(str_repeat('x', 900))));
    }

    public function testAnOrdinaryReplyIsLeftAlone(): void
    {
        $this->assertSame('the title is: Back in ten', TwitchText::safeLine('the title is: Back in ten'));
    }

    // ── Relaying a Discord message ─────────────────────────────────────

    public function testTheMessageIsPrefixedWithItsAuthor(): void
    {
        // Not only cosmetic: the prefix guarantees the line never *begins*
        // with a character Twitch would read as a command.
        $this->assertSame('ada: hello', TwitchText::compose($this->message('ada', 'hello')));
    }

    public function testARelayedSlashCommandCannotReachTwitchAsACommand(): void
    {
        $composed = (string) TwitchText::compose($this->message('ada', '/ban someone'));

        $this->assertStringStartsWith('ada: ', $composed);
    }

    #[DataProvider('botCommandNames')]
    public function testANicknameCannotMakeTheLineAnotherBotsCommand(string $nickname, string $expected): void
    {
        // The bridge account is usually a moderator, and Nightbot and friends
        // run a line starting with `!` from a moderator with a moderator's
        // authority. The nickname is chosen by whoever is speaking.
        $composed = (string) TwitchText::compose($this->message($nickname, 'hi'));

        $this->assertStringStartsWith($expected . ': ', $composed);
        $this->assertMatchesRegularExpression('/^[\p{L}\p{N}]/u', $composed);
    }

    /** @return iterable<string, array{string, string}> */
    public static function botCommandNames(): iterable
    {
        yield 'bang command' => ['!addcom !x', 'addcom !x'];
        yield 'slash' => ['/mod someone', 'mod someone'];
        yield 'dot' => ['.ban someone', 'ban someone'];
        yield 'other prefixes' => ['?$~ cmd', 'cmd'];
        yield 'nothing left' => ['!!!', 'someone'];
        yield 'an ordinary name' => ['Ada', 'Ada'];
    }

    public function testAnEmptyMessageRelaysNothing(): void
    {
        // An embed-only message has nothing a chat can repeat.
        $this->assertNull(TwitchText::compose($this->message('ada', '')));
        $this->assertNull(TwitchText::compose($this->message('ada', '   ')));
    }

    public function testMentionsAreResolvedOnTheWayOut(): void
    {
        $message = new Outgoing('ada', 'hi <@1> in <#2>', ['1' => 'Bob'], ['2' => 'general']);

        $this->assertSame('ada: hi @Bob in #general', TwitchText::compose($message));
    }

    public function testAttachmentsRelayAsLinks(): void
    {
        $composed = (string) TwitchText::compose($this->message('ada', 'look', ['https://cdn.example/a.png']));

        $this->assertStringContainsString('https://cdn.example/a.png', $composed);
    }

    public function testAnAttachmentWithNoTextIsStillWorthRelaying(): void
    {
        $composed = TwitchText::compose($this->message('ada', '', ['https://cdn.example/a.png']));

        $this->assertNotNull($composed);
        $this->assertStringContainsString('https://cdn.example/a.png', (string) $composed);
    }

    public function testALongMessageDoesNotCrowdOutTheLink(): void
    {
        // Links are budgeted before the text and appended after truncation: a
        // URL with its tail cut off is not a URL, a shortened sentence reads.
        $composed = (string) TwitchText::compose(
            $this->message('ada', str_repeat('word ', 200), ['https://cdn.example/a.png']),
        );

        $this->assertStringContainsString('https://cdn.example/a.png', $composed);
        $this->assertLessThanOrEqual(TwitchText::LIMIT, mb_strlen($composed));
    }

    public function testOutputAlwaysFitsTwitchsLimit(): void
    {
        $composed = (string) TwitchText::compose($this->message(str_repeat('n', 100), str_repeat('x', 2000)));

        $this->assertLessThanOrEqual(TwitchText::LIMIT, mb_strlen($composed));
    }

    // ── Naming a channel ───────────────────────────────────────────────

    #[DataProvider('logins')]
    public function testLoginNormalisation(string $input, ?string $expected): void
    {
        $this->assertSame($expected, TwitchText::normalizeLogin($input));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function logins(): iterable
    {
        yield 'bare' => ['twitchdev', 'twitchdev'];
        yield 'cased' => ['TwitchDev', 'twitchdev'];
        yield 'hash' => ['#twitchdev', 'twitchdev'];
        yield 'at' => ['@twitchdev', 'twitchdev'];
        yield 'url' => ['https://twitch.tv/TwitchDev', 'twitchdev'];
        yield 'www url' => ['https://www.twitch.tv/twitchdev', 'twitchdev'];
        yield 'url with path' => ['https://twitch.tv/twitchdev/videos', 'twitchdev'];
        yield 'url with query' => ['twitch.tv/twitchdev?foo=1', 'twitchdev'];
        yield 'spaces' => ['  twitchdev  ', 'twitchdev'];
        yield 'not a login' => ['has spaces', null];
        yield 'punctuation' => ['twitch!dev', null];
        yield 'empty' => ['', null];
        yield 'too long' => [str_repeat('a', 26), null];
    }

    /** @param list<string> $attachments */
    private function message(string $author, string $text, array $attachments = []): Outgoing
    {
        return new Outgoing(
            author: $author,
            text: $text,
            media: array_map(
                static fn (string $url): Media => new Media(Media::IMAGE, $url),
                $attachments,
            ),
        );
    }
}
