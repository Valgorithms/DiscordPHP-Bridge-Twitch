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

    // ── attachments ──────────────────────────────────────────────────────

    /** A long, signed URL of the shape Discord actually issues. */
    private function cdnUrl(string $name = 'cat.png'): string
    {
        return 'https://cdn.discordapp.com/attachments/1234567890123456789/9876543210987654321/'
            . $name . '?ex=671a2b3c&is=6718d9bc&hm=' . str_repeat('a', 64) . '&';
    }

    public function testAttachmentsRelayAsLinks(): void
    {
        $url = $this->cdnUrl();

        self::assertSame('alice: ' . $url, MessageText::forTwitch('', 'alice', attachments: [$url]));
        self::assertSame('alice: look ' . $url, MessageText::forTwitch('look', 'alice', attachments: [$url]));
    }

    /** An attachment with no caption is the whole message; relay it. */
    public function testAttachmentWithoutTextIsStillRelayed(): void
    {
        self::assertNotNull(MessageText::forTwitch('', 'alice', attachments: [$this->cdnUrl()]));
    }

    public function testSeveralAttachmentsAreListedWhileTheyFit(): void
    {
        $text = (string) MessageText::forTwitch('', 'alice', attachments: [
            'https://cdn.discordapp.com/a/1.png',
            'https://cdn.discordapp.com/a/2.png',
        ]);

        self::assertStringContainsString('1.png', $text);
        self::assertStringContainsString('2.png', $text);
        self::assertStringNotContainsString('more', $text);
    }

    /**
     * A truncated URL looks clickable and goes nowhere, so links are never cut
     * mid-way — the ones that do not fit are counted instead.
     */
    public function testAttachmentsThatDoNotFitAreCountedNotTruncated(): void
    {
        $text = (string) MessageText::forTwitch('', 'alice', attachments: [
            $this->cdnUrl('one.png'),
            $this->cdnUrl('two.png'),
            $this->cdnUrl('three.png'),
        ]);

        self::assertLessThanOrEqual(MessageText::TWITCH_LIMIT, mb_strlen($text));
        self::assertStringContainsString('one.png', $text);
        self::assertStringContainsString('more)', $text);
        // Whatever is shown must be whole: no half-written signature.
        self::assertStringNotContainsString('…', $text);
    }

    /**
     * The link is budgeted before the text. The old behaviour appended the
     * marker to the body and then truncated the lot, so a long message cut its
     * own attachment note off the end.
     */
    public function testALongMessageDoesNotCrowdOutTheLink(): void
    {
        $url = $this->cdnUrl();
        $text = (string) MessageText::forTwitch(str_repeat('x', 900), 'alice', attachments: [$url]);

        self::assertLessThanOrEqual(MessageText::TWITCH_LIMIT, mb_strlen($text));
        self::assertStringEndsWith($url, $text);
        self::assertStringContainsString('…', $text, 'the text should be what gets shortened');
    }

    /** Nothing that could break out of the IRC line, and no plaintext links. */
    public function testOnlyWellFormedHttpsLinksAreRelayed(): void
    {
        $text = MessageText::forTwitch('', 'alice', attachments: [
            'http://cdn.discordapp.com/a/insecure.png',
            "https://cdn.discordapp.com/a/b.png\r\nPRIVMSG #x :pwned",
            'javascript:alert(1)',
            '',
        ]);

        // The CRLF one survives sanitisation as a single line, so it is kept —
        // but it must not contain the injection.
        if ($text !== null) {
            self::assertStringNotContainsString("\r", $text);
            self::assertStringNotContainsString("\n", $text);
        }

        self::assertNull(MessageText::forTwitch('', 'alice', attachments: [
            'http://cdn.discordapp.com/a/insecure.png',
            'javascript:alert(1)',
        ]), 'non-https attachments are not relayed as links');
    }

    public function testNoAttachmentsChangesNothing(): void
    {
        self::assertSame('alice: hello', MessageText::forTwitch('hello', 'alice', attachments: []));
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
