<?php

declare(strict_types=1);

namespace TwitchBot\Tests;

use PHPUnit\Framework\TestCase;
use TwitchBot\Api\RepositoryDispatcher;
use TwitchBot\Command\ActionError;

/**
 * The reflective dispatcher that gives `api` its reach.
 *
 * Everything here runs without a network or a `Twitch` instance: discovery,
 * name resolution and signature rendering are all reflection over TwitchPHP's
 * own classes, which is the property that makes the coverage self-maintaining.
 * A repository added upstream shows up here with no change to this project.
 */
final class RepositoryDispatcherTest extends TestCase
{
    private RepositoryDispatcher $dispatcher;

    protected function setUp(): void
    {
        // No constructor: every method under test is reflection-only, and a
        // real Twitch client would want credentials and a loop.
        $this->dispatcher = (new \ReflectionClass(RepositoryDispatcher::class))->newInstanceWithoutConstructor();
    }

    public function testDiscoversEveryRepository(): void
    {
        $repositories = $this->dispatcher->repositories();

        self::assertContains('channels', $repositories);
        self::assertContains('moderation', $repositories);
        self::assertContains('eventSubscriptions', $repositories);
        self::assertGreaterThanOrEqual(29, count($repositories));
    }

    public function testListsEndpointMethods(): void
    {
        $methods = $this->dispatcher->methods('channels');

        self::assertContains('modify', $methods);
        self::assertContains('followers', $methods);
        self::assertContains('editors', $methods);
    }

    /**
     * The inherited collection helpers operate on whatever happens to be
     * cached in memory, which is not what `api users.filter` would imply.
     */
    public function testInheritedCollectionPlumbingIsHidden(): void
    {
        $methods = $this->dispatcher->methods('users');

        self::assertNotContains('filter', $methods);
        self::assertNotContains('first', $methods);
        self::assertNotContains('getIterator', $methods);
        self::assertNotContains('count', $methods);
        self::assertNotContains('collect', $methods);

        // The real lookups are still there under their own names.
        self::assertContains('fetchByLogin', $methods);
        self::assertContains('byLogins', $methods);
    }

    public function testRepositoryNamesAreForgiving(): void
    {
        $canonical = $this->dispatcher->methods('channelPoints');

        self::assertSame($canonical, $this->dispatcher->methods('channelpoints'));
        self::assertSame($canonical, $this->dispatcher->methods('channel_points'));
        self::assertSame($canonical, $this->dispatcher->methods('CHANNELPOINTS'));
    }

    public function testSignatureNamesParametersAndTypes(): void
    {
        $signature = $this->dispatcher->signature('channels.modify');

        self::assertStringContainsString('channels.modify', $signature);
        self::assertStringContainsString('broadcasterId=<string>', $signature);
        self::assertStringContainsString('fields=<array>', $signature);
    }

    public function testOptionalParametersAreBracketed(): void
    {
        // `followers(string $broadcasterId, ?string $userId = null, int $first = 20, ...)`
        $signature = $this->dispatcher->signature('channels.followers');

        self::assertStringContainsString('broadcasterId=<string>', $signature);
        self::assertStringContainsString('[', $signature);
    }

    public function testCallsMayBeWrittenWithASpace(): void
    {
        self::assertSame(
            $this->dispatcher->signature('channels.modify'),
            $this->dispatcher->signature('channels modify'),
        );
    }

    public function testUnknownRepositorySuggestsTheNearest(): void
    {
        $this->expectException(ActionError::class);
        $this->expectExceptionMessageMatches('/channels/');

        $this->dispatcher->methods('chanels');
    }

    public function testUnknownMethodSuggestsTheNearest(): void
    {
        $this->expectException(ActionError::class);
        $this->expectExceptionMessageMatches('/modify/');

        $this->dispatcher->signature('channels.modfy');
    }

    public function testMalformedCallIsExplained(): void
    {
        $this->expectException(ActionError::class);
        $this->expectExceptionMessageMatches('/repository\.method/');

        $this->dispatcher->signature('nonsense');
    }

    /**
     * The headline claim: the whole documented Helix surface is reachable
     * without a hand-written command per endpoint.
     */
    public function testReachesTheWholeApiSurface(): void
    {
        $total = 0;

        foreach ($this->dispatcher->repositories() as $repository) {
            $total += count($this->dispatcher->methods($repository));
        }

        self::assertGreaterThan(120, $total);
    }
}
