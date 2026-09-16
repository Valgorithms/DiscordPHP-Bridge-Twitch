<?php

declare(strict_types=1);

namespace TwitchBot\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TwitchBot\Command\Arguments;

/**
 * The parser both surfaces share.
 *
 * Worth testing directly because the two clients pre-split their tokens by
 * different rules — DiscordPHP uses `str_getcsv`, TwitchPHP splits on runs of
 * whitespace — and the whole point of {@see Arguments} is that a command means
 * the same thing wherever it was typed.
 */
final class ArgumentsTest extends TestCase
{
    public function testReadsPositionalArguments(): void
    {
        $arguments = Arguments::fromString('link twitchdev #general');

        self::assertSame('link', $arguments->get(0));
        self::assertSame('twitchdev', $arguments->get(1));
        self::assertSame('#general', $arguments->get(2));
        self::assertNull($arguments->get(3));
        self::assertSame('fallback', $arguments->get(9, 'fallback'));
    }

    public function testReadsNamedArguments(): void
    {
        $arguments = Arguments::fromString('channels.modify broadcaster_id=29034572 first=5');

        self::assertSame('channels.modify', $arguments->get(0));
        self::assertSame('29034572', $arguments->named('broadcaster_id'));
        self::assertSame('5', $arguments->named('first'));
        self::assertTrue($arguments->has('FIRST'));
        self::assertFalse($arguments->has('nope'));
    }

    /**
     * The regression that prompted the tokenizer rewrite: a naive
     * `"..."|\S+` alternation matches `title="on` here, because `\S+` starts at
     * the word and stops at the first space having already eaten the quote.
     */
    public function testQuotedValueAttachedToAKeyStaysOneToken(): void
    {
        $arguments = Arguments::fromString('title="on the road" first=5');

        self::assertSame('on the road', $arguments->named('title'));
        self::assertSame('5', $arguments->named('first'));
    }

    public function testQuotedPositionalIsUnwrapped(): void
    {
        self::assertSame('two words', Arguments::fromString('"two words"')->get(0));
        self::assertSame('two words', Arguments::fromString("'two words'")->get(0));
    }

    /** A value that merely contains `=` is not a flag. */
    public function testUrlStaysPositional(): void
    {
        $arguments = Arguments::fromString('https://twitch.tv/foo?x=1');

        self::assertSame('https://twitch.tv/foo?x=1', $arguments->get(0));
        self::assertSame([], $arguments->allNamed());
    }

    #[DataProvider('equivalentKeys')]
    public function testKeysAreCaseAndUnderscoreInsensitive(string $written, string $read): void
    {
        self::assertSame('7', Arguments::fromString($written . '=7')->named($read));
    }

    public static function equivalentKeys(): array
    {
        return [
            ['broadcaster_id', 'broadcaster_id'],
            ['BROADCASTER_ID', 'broadcaster_id'],
            ['broadcaster_id', 'BROADCASTER_ID'],
        ];
    }

    /** Free-text commands want the sentence, not the word list. */
    public function testRestReturnsTheWholeLine(): void
    {
        self::assertSame('Back in ten minutes', Arguments::fromString('Back in ten minutes')->rest());
    }

    public function testRestSkipsLeadingTokens(): void
    {
        $arguments = Arguments::fromString('bob 300 being a nuisance');

        self::assertSame('being a nuisance', $arguments->rest(2));
        self::assertSame('300 being a nuisance', $arguments->rest(1));
    }

    public function testRestIsEmptyWhenNothingFollows(): void
    {
        self::assertSame('', Arguments::fromString('title')->rest(1));
    }

    public function testTokensFromAClientAreReparsed(): void
    {
        // What DiscordPHP hands over after its own csv split.
        $arguments = Arguments::fromTokens(['link', 'twitchdev']);

        self::assertSame('link', $arguments->get(0));
        self::assertSame('twitchdev', $arguments->get(1));
        self::assertSame(2, $arguments->count());
    }

    public function testEmptyInput(): void
    {
        $arguments = Arguments::fromString('   ');

        self::assertTrue($arguments->isEmpty());
        self::assertSame(0, $arguments->count());
        self::assertSame([], $arguments->all());
        self::assertSame('', $arguments->rest());
    }

    public function testMixedPositionalAndNamed(): void
    {
        $arguments = Arguments::fromString('moderation.warn user_id=42 reason="being rude"');

        self::assertSame(['moderation.warn'], $arguments->all());
        self::assertSame(['user_id' => '42', 'reason' => 'being rude'], $arguments->allNamed());
    }
}
