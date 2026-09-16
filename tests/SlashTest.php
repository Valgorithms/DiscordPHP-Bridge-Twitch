<?php

declare(strict_types=1);

namespace TwitchBot\Tests;

use PHPUnit\Framework\TestCase;
use TwitchBot\Actions\ApiActions;
use TwitchBot\Actions\ChannelActions;
use TwitchBot\Actions\HelpActions;
use TwitchBot\Actions\ModerationActions;
use TwitchBot\Actions\RelayActions;
use TwitchBot\Actions\StreamActions;
use TwitchBot\Command\Access;
use TwitchBot\Command\Action;
use TwitchBot\Command\ActionRegistry;
use TwitchBot\Command\Arguments;
use TwitchBot\Command\Slash;
use TwitchBot\Command\SlashAdapter;
use TwitchBot\Command\SlashOption;
use TwitchBot\Command\SlashSubcommand;
use TwitchBot\Command\Surface;

/**
 * Slash commands as a third rendering of the same actions.
 *
 * The point of the design is that no handler is written twice, so what needs
 * testing is the seam: that Discord's typed options are turned into exactly
 * the arguments the prefix form would have produced.
 */
final class SlashTest extends TestCase
{
    /** @return array{0: list<string>, 1: array<string, string>} */
    private function values(array $declared, array $supplied): array
    {
        $method = new \ReflectionMethod(SlashAdapter::class, 'values');
        $adapter = (new \ReflectionClass(SlashAdapter::class))->newInstanceWithoutConstructor();

        return $method->invoke($adapter, $declared, new FakeOptionCollection($supplied));
    }

    // ── Option mapping ─────────────────────────────────────────────────

    public function testOptionsAreRecordedBothPositionallyAndByName(): void
    {
        [$positional, $named] = $this->values([
            new SlashOption('twitch', '', SlashOption::STRING, true),
            new SlashOption('channel', '', SlashOption::CHANNEL),
        ], ['twitch' => 'twitchdev', 'channel' => '112233']);

        // Positional, so a handler reading get(1)/get(2) works...
        self::assertSame(['twitchdev', '<#112233>'], $positional);
        // ...and named, so one reading named('twitch') works too.
        self::assertSame(['twitch' => 'twitchdev', 'channel' => '<#112233>'], $named);
    }

    /**
     * A channel arrives as a bare snowflake, but the prefix handler parses
     * `<#id>` because that is what a person types. Rendering it back into that
     * form is what lets one handler serve both.
     */
    public function testTypedValuesAreRenderedAsTheyWouldBeTyped(): void
    {
        [, $named] = $this->values([
            new SlashOption('channel', '', SlashOption::CHANNEL),
            new SlashOption('who', '', SlashOption::USER),
            new SlashOption('count', '', SlashOption::INTEGER),
            new SlashOption('on', '', SlashOption::BOOLEAN),
        ], ['channel' => '1', 'who' => '2', 'count' => 30, 'on' => true]);

        self::assertSame('<#1>', $named['channel']);
        self::assertSame('<@2>', $named['who']);
        self::assertSame('30', $named['count']);
        self::assertSame('true', $named['on']);
    }

    /**
     * The important one. Discord omits an unsupplied optional entirely, so
     * continuing to append positionally past a gap would shift every later
     * value down an index — a handler reading `get(2)` would silently get what
     * was meant for `get(3)`.
     */
    public function testPositionalRecordingStopsAtTheFirstOmittedOption(): void
    {
        [$positional, $named] = $this->values([
            new SlashOption('first', ''),
            new SlashOption('second', ''),
            new SlashOption('third', ''),
        ], ['first' => 'a', 'third' => 'c']);

        // `c` is NOT promoted into the slot `second` would have occupied.
        self::assertSame(['a'], $positional);
        // It is still reachable unambiguously, by name.
        self::assertSame(['first' => 'a', 'third' => 'c'], $named);
    }

    public function testNoOptionsSuppliedYieldsNothing(): void
    {
        [$positional, $named] = $this->values([new SlashOption('text', '')], []);

        self::assertSame([], $positional);
        self::assertSame([], $named);
    }

    // ── Arguments built from parts ─────────────────────────────────────

    public function testArgumentsFromPartsExposeBothForms(): void
    {
        $arguments = Arguments::fromParts(['link', 'twitchdev'], ['twitch' => 'twitchdev']);

        self::assertSame('link', $arguments->get(0));
        self::assertSame('twitchdev', $arguments->get(1));
        self::assertSame('twitchdev', $arguments->named('twitch'));
    }

    /**
     * `/title text:Back in ten` must not be re-split. Round-tripping it through
     * the tokenizer would turn one value into three.
     */
    public function testValuesContainingSpacesSurviveIntact(): void
    {
        $arguments = Arguments::fromParts(['Back in ten minutes'], ['text' => 'Back in ten minutes']);

        self::assertSame('Back in ten minutes', $arguments->rest());
        self::assertSame('Back in ten minutes', $arguments->get(0));
    }

    public function testValuesContainingQuotesSurviveIntact(): void
    {
        $arguments = Arguments::fromParts(['a "quoted" thing']);

        self::assertSame('a "quoted" thing', $arguments->rest());
    }

    public function testRestSkipsTheSubcommandToken(): void
    {
        $arguments = Arguments::fromParts(['link', 'twitchdev']);

        self::assertSame('twitchdev', $arguments->rest(1));
    }

    public function testEmptyPartsAreEmpty(): void
    {
        self::assertTrue(Arguments::fromParts([])->isEmpty());
        self::assertSame('', Arguments::fromParts([])->rest());
    }

    // ── Spec validation ────────────────────────────────────────────────

    public function testOptionsAndSubcommandsCannotBeMixed(): void
    {
        $this->expectException(\LogicException::class);

        new Slash(
            options: [new SlashOption('a', 'a')],
            subcommands: [new SlashSubcommand('b', 'b')],
        );
    }

    public function testTwitchOnlyActionCannotDeclareASlashCommand(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Twitch-only/');

        new Action('x', static fn (): string => '', only: Surface::Twitch, slash: new Slash());
    }

    // ── The shipped specs ──────────────────────────────────────────────

    private function full(): ActionRegistry
    {
        return (new ActionRegistry())
            ->addAll(new HelpActions())
            ->addAll(new RelayActions())
            ->addAll(new ChannelActions())
            ->addAll(new StreamActions())
            ->addAll(new ModerationActions())
            ->addAll(new ApiActions());
    }

    /**
     * Parity with the retired relay, whose entire configuration surface was a
     * `/config` slash command with these four sub-commands.
     */
    public function testRelayIsFullyConfigurableBySlashCommand(): void
    {
        $slash = $this->full()->get('relay')?->slash;

        self::assertInstanceOf(Slash::class, $slash);
        self::assertTrue($slash->hasSubcommands());
        self::assertSame(
            ['link', 'unlink', 'list', 'reset'],
            array_map(static fn (SlashSubcommand $s): string => $s->name, $slash->subcommands),
        );
        self::assertTrue($slash->ephemeral, 'configuration replies should not clutter the channel');
    }

    /** The sub-command names must match what the prefix handler matches on. */
    public function testSlashSubcommandNamesReachTheSameBranches(): void
    {
        $slash = $this->full()->get('relay')?->slash;
        self::assertNotNull($slash);

        foreach ($slash->subcommands as $subcommand) {
            $arguments = Arguments::fromParts([$subcommand->name]);

            self::assertContains(
                strtolower((string) $arguments->get(0)),
                ['link', 'set', 'add', 'unlink', 'unset', 'remove', 'list', 'view', 'show', 'reset', 'clear'],
                "sub-command {$subcommand->name} does not match a branch of RelayActions::relay()",
            );
        }
    }

    public function testLinkTakesARequiredTwitchNameAndAnOptionalChannel(): void
    {
        $link = null;
        foreach ($this->full()->get('relay')?->slash?->subcommands ?? [] as $subcommand) {
            if ($subcommand->name === 'link') {
                $link = $subcommand;
            }
        }

        self::assertNotNull($link);
        self::assertSame('twitch', $link->options[0]->name);
        self::assertTrue($link->options[0]->required);
        self::assertSame('channel', $link->options[1]->name);
        self::assertFalse($link->options[1]->required, 'defaults to the current channel');
        self::assertSame(SlashOption::CHANNEL, $link->options[1]->type);
    }

    public function testCommonActionsAreReachableBySlashCommand(): void
    {
        $registry = $this->full();

        foreach (['title', 'game', 'tags', 'channel', 'uptime', 'viewers', 'stream', 'search', 'help'] as $name) {
            self::assertNotNull($registry->get($name)?->slash, "{$name} should have a slash command");
        }
    }

    /**
     * Everything reachable by prefix on Discord is reachable by slash command.
     *
     * Message Content is a privileged intent, so a server that has not granted
     * it gets no prefix commands at all. If this drifts, that server silently
     * loses whichever command was left out.
     */
    public function testEveryDiscordActionHasASlashCommand(): void
    {
        $missing = [];

        foreach ($this->full()->forSurface(Surface::Discord) as $name => $action) {
            if ($action->slash === null) {
                $missing[] = $name;
            }
        }

        self::assertSame([], $missing, 'these are unreachable without Message Content: ' . implode(', ', $missing));
    }

    /** Discord allows 100 global commands per application. */
    public function testCommandCountFitsDiscordsLimit(): void
    {
        self::assertLessThanOrEqual(100, count($this->full()->forSurface(Surface::Discord)));
    }

    /** No action may declare a slash command it cannot be reached by. */
    public function testEverySlashActionIsAvailableOnDiscord(): void
    {
        foreach ($this->full()->all() as $action) {
            if ($action->slash !== null) {
                self::assertTrue($action->availableOn(Surface::Discord), "{$action->name} declares slash but is not on Discord");
            }
        }
    }

    /** Discord rejects a command description longer than 100 characters. */
    public function testDescriptionsFitDiscordsLimit(): void
    {
        $describe = new \ReflectionMethod(SlashAdapter::class, 'describe');
        $adapter = (new \ReflectionClass(SlashAdapter::class))->newInstanceWithoutConstructor();

        foreach ($this->full()->all() as $action) {
            if ($action->slash === null) {
                continue;
            }

            $rendered = $describe->invoke($adapter, $action);
            self::assertLessThanOrEqual(100, mb_strlen($rendered), "{$action->name} description too long");
            self::assertNotSame('', $rendered);

            foreach ($action->slash->subcommands as $subcommand) {
                self::assertLessThanOrEqual(100, mb_strlen($subcommand->description));
                self::assertNotSame('', $subcommand->description);

                foreach ($subcommand->options as $option) {
                    self::assertLessThanOrEqual(100, mb_strlen($option->description));
                    self::assertNotSame('', $option->description);
                }
            }

            foreach ($action->slash->options as $option) {
                self::assertLessThanOrEqual(100, mb_strlen($option->description));
                self::assertNotSame('', $option->description);
            }
        }
    }

    /** Discord requires lower-case names matching a restricted pattern. */
    public function testNamesAreValidSlashCommandNames(): void
    {
        foreach ($this->full()->all() as $action) {
            if ($action->slash === null) {
                continue;
            }

            self::assertMatchesRegularExpression('/^[a-z0-9_-]{1,32}$/', $action->name);

            foreach ($action->slash->subcommands as $subcommand) {
                self::assertMatchesRegularExpression('/^[a-z0-9_-]{1,32}$/', $subcommand->name);

                foreach ($subcommand->options as $option) {
                    self::assertMatchesRegularExpression('/^[a-z0-9_-]{1,32}$/', $option->name);
                }
            }

            foreach ($action->slash->options as $option) {
                self::assertMatchesRegularExpression('/^[a-z0-9_-]{1,32}$/', $option->name);
            }
        }
    }

    /** Discord requires required options to precede optional ones. */
    public function testRequiredOptionsComeFirst(): void
    {
        foreach ($this->full()->all() as $action) {
            if ($action->slash === null) {
                continue;
            }

            $lists = $action->slash->options !== [] ? [$action->slash->options] : [];
            foreach ($action->slash->subcommands as $subcommand) {
                $lists[] = $subcommand->options;
            }

            foreach ($lists as $options) {
                $seenOptional = false;

                foreach ($options as $option) {
                    if (! $option->required) {
                        $seenOptional = true;

                        continue;
                    }

                    self::assertFalse($seenOptional, "{$action->name}: required option '{$option->name}' follows an optional one");
                }
            }
        }
    }

    public function testConfigurationCommandsAreGatedAboveEveryone(): void
    {
        self::assertSame(Access::Broadcaster, $this->full()->get('relay')?->access);
    }
}

/**
 * Stands in for the option collection DiscordPHP hands over on an interaction:
 * all {@see SlashAdapter::values()} needs of it is `get('name', $x)?->value`.
 */
final class FakeOptionCollection
{
    /** @param array<string, mixed> $values */
    public function __construct(private readonly array $values)
    {
    }

    public function get(string $discriminator, string $key): ?object
    {
        if (! array_key_exists($key, $this->values)) {
            return null;
        }

        return new class ($this->values[$key]) {
            public function __construct(public readonly mixed $value)
            {
            }
        };
    }
}
