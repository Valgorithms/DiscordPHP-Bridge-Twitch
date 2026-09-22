<?php

declare(strict_types=1);

namespace Bridge\Twitch\Tests;

use Bridge\Twitch\TwitchConnector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The scopes requested at authorization time.
 *
 * Worth pinning down, because getting this wrong fails late and confusingly: a
 * missing scope is not caught at startup, or when the command is registered,
 * but at the moment someone runs it — as a 401 from Twitch, on a token that
 * validated perfectly and had just finished serving another command.
 *
 * Every scope below was taken from the Twitch OpenAPI description, not from the
 * documentation pages, which have been wrong about paths before.
 *
 * @link https://github.com/DmitryScaletta/twitch-api-swagger
 */
final class ScopesTest extends TestCase
{
    /**
     * Scope => the commands that stop working without it.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function requiredScopes(): array
    {
        return [
            'relay reads chat' => ['chat:read', 'the relay, inbound'],
            'relay speaks' => ['chat:edit', 'the relay and every command reply'],
            'title/game/tags/marker' => ['channel:manage:broadcast', 'title, game, tags, marker'],
            'follower count' => ['moderator:read:followers', 'followers'],
            'clipping' => ['clips:edit', 'clip'],
            'bans' => ['moderator:manage:banned_users', 'ban, unban, timeout'],
            'message deletion' => ['moderator:manage:chat_messages', 'clear'],
            'announcements' => ['moderator:manage:announcements', 'announce'],
            'shoutouts' => ['moderator:manage:shoutouts', 'shoutout'],
            'chat modes' => ['moderator:manage:chat_settings', 'slow, subonly, emoteonly, followersonly'],
            'vips' => ['channel:manage:vips', 'vip, unvip'],
            'moderators' => ['channel:manage:moderators', 'mod, unmod'],
            'raids' => ['channel:manage:raids', 'raid, unraid'],
            'commercials' => ['channel:edit:commercial', 'commercial'],
        ];
    }

    #[DataProvider('requiredScopes')]
    public function testEveryImplementedCommandHasItsScope(string $scope, string $commands): void
    {
        self::assertContains(
            $scope,
            TwitchConnector::SCOPES,
            "without {$scope}, these fail at runtime with a missing-scope 401: {$commands}",
        );
    }

    /**
     * Nothing is requested speculatively.
     *
     * An unused scope makes the consent screen longer and hands the bot
     * authority it has no code to exercise. `api` can reach endpoints beyond
     * this list on purpose — those fail with a MissingScopeException naming
     * what they wanted, which is the better trade.
     */
    public function testNoScopeIsRequestedWithoutACommandNeedingIt(): void
    {
        $justified = array_map(
            static fn (array $row): string => $row[0],
            array_values(self::requiredScopes()),
        );

        self::assertSame(
            [],
            array_values(array_diff(TwitchConnector::SCOPES, $justified)),
            'these scopes are requested but no command uses them',
        );
    }

    public function testScopesAreUniqueAndWellFormed(): void
    {
        self::assertSame(
            TwitchConnector::SCOPES,
            array_values(array_unique(TwitchConnector::SCOPES)),
            'duplicate scopes',
        );

        foreach (TwitchConnector::SCOPES as $scope) {
            self::assertMatchesRegularExpression('/^[a-z]+(:[a-z_]+)+$/', $scope, "malformed scope: {$scope}");
        }
    }

    /**
     * The relay is the one feature that must work even on a minimal grant, so
     * its two scopes are asserted separately from the rest.
     */
    public function testChatScopesArePresentForTheRelay(): void
    {
        self::assertContains('chat:read', TwitchConnector::SCOPES);
        self::assertContains('chat:edit', TwitchConnector::SCOPES);
    }
}
