<?php

namespace TwitchBot\Tests;

use PHPUnit\Framework\TestCase;
use TwitchBot\Relay\WebhookDelivery;

/**
 * Discord rejects the whole webhook call — losing the message — if the
 * username breaks one of its rules, so the rules are pinned here.
 */
final class WebhookDeliveryTest extends TestCase
{
    public function testNameIsMarkedAsComingFromTwitch(): void
    {
        self::assertSame('Ninja (twitch)', WebhookDelivery::safeUsername('Ninja'));
    }

    /** Discord rejects any webhook username containing "discord". */
    public function testTheWordDiscordIsNeutralised(): void
    {
        self::assertStringNotContainsStringIgnoringCase('discord', WebhookDelivery::safeUsername('discordmod'));
        self::assertStringNotContainsStringIgnoringCase('discord', WebhookDelivery::safeUsername('DiScOrD_fan'));
    }

    public function testLongNamesStayWithinTheLimit(): void
    {
        $name = WebhookDelivery::safeUsername(str_repeat('z', 200));

        self::assertLessThanOrEqual(WebhookDelivery::USERNAME_LIMIT, mb_strlen($name));
    }

    /** The suffix counts toward the cap — it is easy to truncate to exactly 80 and then append. */
    public function testTruncationAccountsForTheSuffix(): void
    {
        $name = WebhookDelivery::safeUsername(str_repeat('z', WebhookDelivery::USERNAME_LIMIT));

        self::assertSame(WebhookDelivery::USERNAME_LIMIT, mb_strlen($name));
        self::assertStringEndsWith(' (twitch)', $name);
    }

    public function testEmptyNameFallsBackToAPlaceholder(): void
    {
        self::assertSame('twitch user (twitch)', WebhookDelivery::safeUsername(''));
        self::assertSame('twitch user (twitch)', WebhookDelivery::safeUsername('   '));
    }

    public function testMultibyteNamesAreCountedInCharactersNotBytes(): void
    {
        $name = WebhookDelivery::safeUsername(str_repeat('あ', 100));

        self::assertLessThanOrEqual(WebhookDelivery::USERNAME_LIMIT, mb_strlen($name));
    }
}
