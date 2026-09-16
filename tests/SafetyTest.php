<?php

declare(strict_types=1);

namespace TwitchBot\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TwitchBot\Api\Sensitive;
use TwitchBot\Command\Access;
use TwitchBot\Command\Context;
use TwitchBot\Command\TwitchAdapter;

/**
 * The checks that stop the bot doing damage: IRC line safety, credential
 * redaction, and the permission ladder.
 *
 * These are unit-testable on purpose. Every one of them guards something that
 * fails silently and expensively in production — an injected IRC command, a
 * stream key in a public channel, a viewer running `api` — and none of them
 * should need a live connection to verify.
 */
final class SafetyTest extends TestCase
{
    // ── IRC line safety ────────────────────────────────────────────────

    /**
     * `Irc::say()` interpolates straight into `PRIVMSG #chan :<text>\r\n`, so
     * a CR or LF ends the line and the remainder is parsed as a fresh IRC
     * command — letting anyone who can make the bot speak issue arbitrary IRC
     * as the bot account.
     */
    #[DataProvider('injectionAttempts')]
    public function testControlCharactersCannotEndTheLine(string $attack): void
    {
        $safe = TwitchAdapter::safeLine($attack);

        self::assertStringNotContainsString("\r", $safe);
        self::assertStringNotContainsString("\n", $safe);
        self::assertStringNotContainsString("\x00", $safe);
    }

    public static function injectionAttempts(): array
    {
        return [
            'crlf' => ["hello\r\nPRIVMSG #victim :pwned"],
            'lf only' => ["hello\nJOIN #victim"],
            'cr only' => ["hello\rPART #home"],
            'null byte' => ["hello\x00QUIT"],
            'vertical tab' => ["hello\x0bNICK evil"],
            'form feed' => ["hello\x0cPRIVMSG #x :y"],
        ];
    }

    /**
     * Twitch reads a leading `/` or `.` as a chat command. A reply that begins
     * with one — an API error quoting a path, say — would be executed rather
     * than said.
     */
    #[DataProvider('commandPrefixes')]
    public function testLeadingCommandCharacterIsNeutralised(string $text): void
    {
        $safe = TwitchAdapter::safeLine($text);

        self::assertNotSame('/', $safe[0] ?? '');
        self::assertNotSame('.', $safe[0] ?? '');
        self::assertStringContainsString(ltrim($text, '/.'), $safe);
    }

    public static function commandPrefixes(): array
    {
        return [
            ['/ban someone'],
            ['.mods'],
            ['/me waves'],
            ['/timeout bob 600'],
        ];
    }

    public function testOrdinaryTextIsUnchanged(): void
    {
        self::assertSame('hello there', TwitchAdapter::safeLine('hello there'));
        self::assertSame('title set to: Back in ten', TwitchAdapter::safeLine('title set to: Back in ten'));
    }

    public function testLinesAreClampedToTwitchLimit(): void
    {
        self::assertLessThanOrEqual(500, mb_strlen(TwitchAdapter::safeLine(str_repeat('x', 2000))));
    }

    public function testEmptyStaysEmpty(): void
    {
        self::assertSame('', TwitchAdapter::safeLine(''));
        self::assertSame('', TwitchAdapter::safeLine("\r\n"));
    }

    // ── Credential handling ────────────────────────────────────────────

    #[DataProvider('secretCalls')]
    public function testCallsThatReturnCredentialsAreFlagged(string $call): void
    {
        self::assertTrue(Sensitive::isSecret($call));
    }

    public static function secretCalls(): array
    {
        return [
            'stream key' => ['streams.key'],
            'stream key, cased' => ['Streams.Key'],
            'own user, carries email' => ['users.me'],
            'analytics are signed urls' => ['analytics.games'],
            'analytics extensions' => ['analytics.extensions'],
        ];
    }

    #[DataProvider('ordinaryCalls')]
    public function testOrdinaryCallsAreNotFlagged(string $call): void
    {
        self::assertFalse(Sensitive::isSecret($call));
    }

    public static function ordinaryCalls(): array
    {
        return [
            ['channels.modify'],
            ['streams.live'],
            ['users.fetchByLogin'],
            ['moderation.ban'],
            ['games.top'],
        ];
    }

    /**
     * The second line of defence, and the one that matters most: it catches
     * the endpoint nobody thought to add to the list, including ones added to
     * TwitchPHP after this file was last read.
     */
    public function testCredentialFieldsAreMaskedAnywhereInAResponse(): void
    {
        $redacted = Sensitive::redact([
            'data' => [
                ['stream_key' => 'live_12345_abcdefghijk', 'broadcaster_id' => '29034572'],
            ],
            'nested' => ['deep' => ['client_secret' => 'hunter2']],
        ]);

        self::assertStringContainsString('redacted', (string) $redacted['data'][0]['stream_key']);
        self::assertStringNotContainsString('live_12345', (string) $redacted['data'][0]['stream_key']);
        self::assertSame('29034572', $redacted['data'][0]['broadcaster_id']);
        self::assertStringContainsString('redacted', (string) $redacted['nested']['deep']['client_secret']);
    }

    /**
     * `url` is on the field list because pre-signed report links are as good as
     * the data, but blanking every URL would make half the API unreadable. Only
     * the signed ones go.
     */
    public function testPlainUrlsSurviveAndSignedOnesDoNot(): void
    {
        $redacted = Sensitive::redact([
            'profile_image_url' => 'https://static-cdn.jtvnw.net/user.png',
            'thumbnail_url' => 'https://static-cdn.jtvnw.net/preview.jpg',
            'url' => 'https://reports.twitch.tv/x.csv?Signature=abc&Expires=1',
        ]);

        self::assertSame('https://static-cdn.jtvnw.net/user.png', $redacted['profile_image_url']);
        self::assertSame('https://static-cdn.jtvnw.net/preview.jpg', $redacted['thumbnail_url']);
        self::assertStringContainsString('redacted', (string) $redacted['url']);
    }

    public function testRedactionLeavesScalarsAlone(): void
    {
        self::assertSame('plain', Sensitive::redact('plain'));
        self::assertSame(42, Sensitive::redact(42));
        self::assertNull(Sensitive::redact(null));
    }

    public function testFieldNamesAreMatchedWholeOrSuffixed(): void
    {
        self::assertTrue(Sensitive::isSecretField('key'));
        self::assertTrue(Sensitive::isSecretField('stream_key'));
        self::assertTrue(Sensitive::isSecretField('access_token'));
        // Not a credential merely for containing the letters.
        self::assertFalse(Sensitive::isSecretField('keyboard'));
        self::assertFalse(Sensitive::isSecretField('monkey'));
    }

    // ── Permission ladder ──────────────────────────────────────────────

    public function testLadderIsOrdered(): void
    {
        self::assertTrue(Access::Owner->satisfies(Access::Broadcaster));
        self::assertTrue(Access::Owner->satisfies(Access::Everyone));
        self::assertTrue(Access::Broadcaster->satisfies(Access::Moderator));
        self::assertTrue(Access::Moderator->satisfies(Access::Everyone));

        self::assertFalse(Access::Moderator->satisfies(Access::Broadcaster));
        self::assertFalse(Access::Broadcaster->satisfies(Access::Owner));
        self::assertFalse(Access::Everyone->satisfies(Access::Moderator));
    }

    public function testTwitchBadgesMapOntoTheLadder(): void
    {
        self::assertSame(Access::Owner, Context::twitchAccess(false, false, true));
        self::assertSame(Access::Broadcaster, Context::twitchAccess(true, false, false));
        self::assertSame(Access::Moderator, Context::twitchAccess(false, true, false));
        self::assertSame(Access::Everyone, Context::twitchAccess(false, false, false));
    }

    /** The operator outranks the broadcaster, on both platforms. */
    public function testOwnerOutranksEverything(): void
    {
        self::assertSame(Access::Owner, Context::twitchAccess(true, true, true));
        self::assertSame(Access::Owner, Context::discordAccess(true, true, true, true));
    }

    public function testDiscordRolesMapOntoTheLadder(): void
    {
        self::assertSame(Access::Broadcaster, Context::discordAccess(true, false, false, false), 'guild owner');
        self::assertSame(Access::Broadcaster, Context::discordAccess(false, true, false, false), 'administrator');
        self::assertSame(Access::Moderator, Context::discordAccess(false, false, true, false));
        self::assertSame(Access::Everyone, Context::discordAccess(false, false, false, false));
    }
}
