<?php

declare(strict_types=1);

namespace TwitchBot\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TwitchBot\Support\GatewayDiagnostics;

/**
 * Explaining a fatal gateway close.
 *
 * The failure this exists for is indistinguishable from the outside: the bot
 * connects, identifies, and is dropped a second later. DiscordPHP logs "not
 * reconnecting - critical op code" and puts the code in the record's context,
 * so a log format that prints only the message leaves nothing to go on — and
 * the two usual causes, a wrong token and an unticked intent, need completely
 * different fixes.
 */
final class GatewayDiagnosticsTest extends TestCase
{
    #[DataProvider('explainedCodes')]
    public function testFatalCodesNameTheThingToChange(int $op, string $mustMention): void
    {
        $explanation = GatewayDiagnostics::explain($op);

        self::assertNotNull($explanation, "code {$op} should be explained");
        self::assertStringContainsString($mustMention, $explanation);
    }

    public static function explainedCodes(): array
    {
        return [
            'bad token points at the bot token' => [4004, 'BOT token'],
            'disallowed intents points at the toggle' => [4014, 'MESSAGE CONTENT INTENT'],
            'invalid intents points at the bitfield' => [4013, 'Bot::INTENTS'],
            'sharding' => [4011, 'sharding'],
            'bad shard config' => [4010, 'shard'],
            'gateway version' => [4012, 'DiscordPHP'],
        ];
    }

    /**
     * The most likely cause on a newly created application, so it has to say
     * where the toggle is rather than merely that intents were refused.
     */
    public function testDisallowedIntentsIsActionable(): void
    {
        $explanation = (string) GatewayDiagnostics::explain(4014);

        self::assertStringContainsString('Privileged Gateway Intents', $explanation);
        self::assertStringContainsString('Slash commands do NOT need it', $explanation);
    }

    /** A wrong token is usually the wrong *kind* of token, not a typo. */
    public function testBadTokenNamesTheLookalikes(): void
    {
        $explanation = (string) GatewayDiagnostics::explain(4004);

        self::assertStringContainsString('Client Secret', $explanation);
        self::assertStringContainsString('Application ID', $explanation);
    }

    public function testUnknownCodeHasNoInventedAdvice(): void
    {
        self::assertNull(GatewayDiagnostics::explain(4000));
        self::assertNull(GatewayDiagnostics::explain(1006));
    }

    /** An unexplained code still gets a report, so nothing fails silently. */
    public function testReportAlwaysSaysSomething(): void
    {
        $report = GatewayDiagnostics::report(4000, 'Unknown error');

        self::assertStringContainsString('4000', $report);
        self::assertStringContainsString('Unknown error', $report);
    }

    public function testReportIncludesDiscordsOwnReason(): void
    {
        // Worth keeping alongside the explanation: it is the string someone
        // will paste into a search engine.
        self::assertStringContainsString(
            'Disallowed intent(s).',
            GatewayDiagnostics::report(4014, 'Disallowed intent(s).'),
        );
    }

    public function testReportOmitsAnEmptyReason(): void
    {
        self::assertStringNotContainsString('Discord said:', GatewayDiagnostics::report(4014, '   '));
    }

    #[DataProvider('fatalCodes')]
    public function testFatalCodesAreRecognised(int $op): void
    {
        self::assertTrue(GatewayDiagnostics::isFatal($op));
    }

    public static function fatalCodes(): array
    {
        return [[4004], [4010], [4011], [4012], [4013], [4014]];
    }

    public function testRecoverableCodesAreNotFatal(): void
    {
        // 4000/4009 reconnect on their own; explaining them would be noise.
        self::assertFalse(GatewayDiagnostics::isFatal(4000));
        self::assertFalse(GatewayDiagnostics::isFatal(4009));
        self::assertFalse(GatewayDiagnostics::isFatal(1006));
    }

    // ── Reading the code back off the log record ───────────────────────

    public function testRecognisesTheCriticalCloseRecord(): void
    {
        self::assertSame(
            4014,
            GatewayDiagnostics::fromLogContext('not reconnecting - critical op code', ['op' => 4014, 'reason' => 'x']),
        );
    }

    public function testIgnoresEveryOtherRecord(): void
    {
        self::assertNull(GatewayDiagnostics::fromLogContext('starting with 33 actions', []));
        self::assertNull(GatewayDiagnostics::fromLogContext('websocket closed', ['op' => 4014]));
        self::assertNull(GatewayDiagnostics::fromLogContext('[relay] twitch ready', ['op' => 4014]));
    }

    public function testIgnoresACriticalRecordWithoutAUsableCode(): void
    {
        self::assertNull(GatewayDiagnostics::fromLogContext('not reconnecting - critical op code', []));
        self::assertNull(GatewayDiagnostics::fromLogContext('not reconnecting - critical op code', ['op' => 'nope']));
        // A non-fatal code in that message would be a library change; ignore it
        // rather than print advice that may no longer apply.
        self::assertNull(GatewayDiagnostics::fromLogContext('not reconnecting - critical op code', ['op' => 4000]));
    }

    public function testAcceptsANumericStringCode(): void
    {
        self::assertSame(
            4004,
            GatewayDiagnostics::fromLogContext('not reconnecting - critical op code', ['op' => '4004']),
        );
    }
}
