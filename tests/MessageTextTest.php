<?php

namespace TwitchBot\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TwitchBot\Support\MessageText;

final class MessageTextTest extends TestCase
{
    // ── IRC injection ────────────────────────────────────────────────────

    /**
     * The one that matters. `Irc::say()` interpolates into
     * `PRIVMSG #chan :<text>\r\n`, so a CRLF in relayed text ends the line and
     * the rest is parsed as a fresh IRC command — letting anyone who can type
     * in the Discord channel issue arbitrary IRC as the bot.
     */
    public function testCarriageReturnsCannotInjectAnIrcCommand(): void
    {
        $evil = "hello\r\nPART #victim\r\nPRIVMSG #victim :pwned";

        $clean = MessageText::sanitizeIrc($evil);

        self::assertStringNotContainsString("\r", $clean);
        self::assertStringNotContainsString("\n", $clean);
        self::assertSame('hello PART #victim PRIVMSG #victim :pwned', $clean);
    }

    public function testControlCharactersAreStripped(): void
    {
        self::assertSame('abc', MessageText::sanitizeIrc("a\x00b\x07c"));
    }

    public function testNewlinesBecomeSpacesRatherThanVanishing(): void
    {
        // "one\ntwo" must not become "onetwo".
        self::assertSame('one two', MessageText::sanitizeIrc("one\ntwo"));
    }

    // ── Discord → Twitch ─────────────────────────────────────────────────

    public function testMessageIsPrefixedWithTheAuthor(): void
    {
        self::assertSame('alice: hello', MessageText::forTwitch('hello', 'alice'));
    }

    /**
     * Twitch reads a leading `/` or `.` as a command. The author prefix means
     * a relayed line can never start with one — so a Discord user typing
     * "/ban someone" cannot make a moderator bot run it.
     */
    public function testARelayedSlashCommandCannotReachTwitchAsACommand(): void
    {
        $text = MessageText::forTwitch('/ban someone', 'mallory');

        self::assertNotNull($text);
        self::assertStringStartsNotWith('/', $text);
        self::assertStringStartsNotWith('.', $text);
        self::assertSame('mallory: /ban someone', $text);
    }

    public function testEmptyMessageRelaysNothing(): void
    {
        self::assertNull(MessageText::forTwitch('', 'alice'));
        self::assertNull(MessageText::forTwitch('   ', 'alice'));
    }

    public function testAttachmentOnlyMessageIsAnnounced(): void
    {
        self::assertSame('alice: [1 attachment]', MessageText::forTwitch('', 'alice', attachments: 1));
        self::assertSame('alice: look [2 attachments]', MessageText::forTwitch('look', 'alice', attachments: 2));
    }

    public function testOutputFitsTwitchsLimit(): void
    {
        $text = MessageText::forTwitch(str_repeat('x', 900), 'alice');

        self::assertNotNull($text);
        self::assertLessThanOrEqual(MessageText::TWITCH_LIMIT, mb_strlen($text));
    }

    // ── mentions ─────────────────────────────────────────────────────────

    public function testMentionsResolveToNames(): void
    {
        $out = MessageText::resolveMentions(
            'hi <@123> see <#456> ping <@&789>',
            ['123' => 'alice'],
            ['456' => 'general'],
            ['789' => 'mods'],
        );

        self::assertSame('hi @alice see #general ping @mods', $out);
    }

    public function testUnknownMentionsDoNotLeakRawIds(): void
    {
        $out = MessageText::resolveMentions('hi <@999>');

        self::assertStringNotContainsString('999', $out);
        self::assertSame('hi @someone', $out);
    }

    public function testCustomEmojiBecomesItsName(): void
    {
        self::assertSame(':kappa:', MessageText::resolveMentions('<:kappa:12345>'));
        self::assertSame(':wave:', MessageText::resolveMentions('<a:wave:12345>'));
    }

    // ── Twitch → Discord ─────────────────────────────────────────────────

    public function testTwitchTextPassesThroughUnescaped(): void
    {
        // Mentions are neutered by allowed_mentions at the API, not by
        // mangling what the viewer actually typed.
        self::assertSame('hey @everyone', MessageText::forDiscord('hey @everyone'));
    }

    public function testDiscordOutputFitsTheLimit(): void
    {
        $text = MessageText::forDiscord(str_repeat('y', 2500));

        self::assertNotNull($text);
        self::assertLessThanOrEqual(MessageText::DISCORD_LIMIT, mb_strlen($text));
    }

    public function testEmptyTwitchMessageRelaysNothing(): void
    {
        self::assertNull(MessageText::forDiscord("\x00  "));
    }

    // ── login normalisation ──────────────────────────────────────────────

    #[DataProvider('logins')]
    public function testLoginNormalisation(string $input, ?string $expected): void
    {
        self::assertSame($expected, MessageText::normalizeLogin($input));
    }

    public static function logins(): array
    {
        return [
            'bare' => ['twitchdev', 'twitchdev'],
            'hash' => ['#TwitchDev', 'twitchdev'],
            'at' => ['@twitchdev', 'twitchdev'],
            'url' => ['https://twitch.tv/TwitchDev', 'twitchdev'],
            'www url' => ['https://www.twitch.tv/twitchdev', 'twitchdev'],
            'url with path' => ['twitch.tv/twitchdev/videos', null],
            'url with query' => ['https://twitch.tv/twitchdev?x=1', 'twitchdev'],
            'spaces' => ['  twitchdev  ', 'twitchdev'],
            'invalid chars' => ['not a channel!', null],
            'empty' => ['', null],
            'too long' => [str_repeat('a', 26), null],
        ];
    }
}
