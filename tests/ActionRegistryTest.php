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
use TwitchBot\Command\Surface;

/**
 * The catalogue both adapters read from.
 *
 * The collision tests are the valuable ones. Names and aliases share a
 * namespace across every provider, and the failure mode without a check is
 * silent: one action shadows another, with nothing in the log. This suite
 * caught exactly that during development, where `followers` was both a
 * command in {@see StreamActions} and an alias in {@see ModerationActions}.
 */
final class ActionRegistryTest extends TestCase
{
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

    private function action(string $name, array $aliases = [], Access $access = Access::Everyone, ?Surface $only = null): Action
    {
        return new Action($name, static fn (): string => 'ok', aliases: $aliases, access: $access, only: $only);
    }

    /** The shipped set must not collide — this is the regression guard. */
    public function testEveryShippedActionRegisters(): void
    {
        $registry = $this->full();

        self::assertGreaterThan(25, $registry->count());
    }

    public function testDuplicateNameThrows(): void
    {
        $registry = (new ActionRegistry())->add($this->action('title'));

        $this->expectException(\LogicException::class);
        $registry->add($this->action('title'));
    }

    public function testAliasCollidingWithACommandThrows(): void
    {
        $registry = (new ActionRegistry())->add($this->action('followers'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/followers/');
        $registry->add($this->action('followersonly', ['followers']));
    }

    public function testCommandCollidingWithAnAliasThrows(): void
    {
        $registry = (new ActionRegistry())->add($this->action('followersonly', ['followers']));

        $this->expectException(\LogicException::class);
        $registry->add($this->action('followers'));
    }

    public function testAliasesResolveToTheirAction(): void
    {
        $registry = $this->full();

        self::assertSame('shoutout', $registry->get('so')?->name);
        self::assertSame('game', $registry->get('category')?->name);
        self::assertSame('timeout', $registry->get('to')?->name);
    }

    public function testLookupIsCaseInsensitive(): void
    {
        $registry = (new ActionRegistry())->add($this->action('Title'));

        self::assertNotNull($registry->get('TITLE'));
        self::assertNotNull($registry->get('title'));
    }

    public function testSurfaceRestriction(): void
    {
        $registry = $this->full();

        // `relay` needs a Discord channel to point at, so it cannot work from
        // Twitch chat and is not offered there.
        self::assertArrayNotHasKey('relay', $registry->forSurface(Surface::Twitch));
        self::assertArrayHasKey('relay', $registry->forSurface(Surface::Discord));

        // `bridge` is read-only and answers on both.
        self::assertArrayHasKey('bridge', $registry->forSurface(Surface::Twitch));
        self::assertArrayHasKey('bridge', $registry->forSurface(Surface::Discord));
    }

    /**
     * Help lists only what the asker can run. Showing a viewer a command they
     * will be refused is worse than not showing it.
     */
    public function testGroupedHidesWhatTheAskerCannotRun(): void
    {
        $registry = $this->full();

        $visible = static function (array $groups): array {
            $names = [];
            foreach ($groups as $actions) {
                foreach ($actions as $action) {
                    $names[] = $action->name;
                }
            }

            return $names;
        };

        $viewer = $visible($registry->grouped(Surface::Twitch, Access::Everyone));
        self::assertContains('uptime', $viewer);
        self::assertNotContains('ban', $viewer);
        self::assertNotContains('api', $viewer);

        $moderator = $visible($registry->grouped(Surface::Twitch, Access::Moderator));
        self::assertContains('ban', $moderator);
        self::assertNotContains('mod', $moderator, 'handing out the moderator badge is the broadcaster’s call');
        self::assertNotContains('api', $moderator);

        $owner = $visible($registry->grouped(Surface::Twitch, Access::Owner));
        self::assertContains('api', $owner);
    }

    public function testPermissionRungsOnDangerousActions(): void
    {
        $registry = $this->full();

        self::assertSame(Access::Owner, $registry->get('api')?->access);
        self::assertSame(Access::Broadcaster, $registry->get('mod')?->access);
        self::assertSame(Access::Broadcaster, $registry->get('raid')?->access);
        self::assertSame(Access::Broadcaster, $registry->get('commercial')?->access);
        self::assertSame(Access::Moderator, $registry->get('ban')?->access);
        self::assertSame(Access::Moderator, $registry->get('timeout')?->access);
        self::assertSame(Access::Everyone, $registry->get('uptime')?->access);
    }

    public function testUnknownNameReturnsNull(): void
    {
        self::assertNull($this->full()->get('definitely-not-a-command'));
        self::assertFalse($this->full()->has('definitely-not-a-command'));
    }

    public function testHelpLineRenders(): void
    {
        $action = new Action('title', static fn (): string => '', 'Set the title', '[new title]');

        self::assertSame('!title [new title] — Set the title', $action->help('!'));
        self::assertSame('?title [new title] — Set the title', $action->help('?'));
    }
}
