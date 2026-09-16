<?php

declare(strict_types=1);

namespace TwitchBot\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TwitchBot\Config;

final class ConfigTest extends TestCase
{
    private const POLLUTES = [
        'DISCORD_TOKEN', 'TWITCH_CLIENT_ID', 'TWITCH_CLIENT_SECRET', 'TWITCH_NICK',
        'DISCORD_OWNER_ID', 'TWITCH_OWNER_LOGIN', 'DISCORD_PREFIX', 'TWITCH_PREFIX', 'LOG_LEVEL',
    ];

    private string $envPath;

    protected function setUp(): void
    {
        $this->envPath = sys_get_temp_dir() . '/twitchbot_env_' . getmypid();

        // getenv() wins over the file, so make sure the harness isn't polluted.
        foreach (self::POLLUTES as $key) {
            putenv($key);
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->envPath);

        foreach (self::POLLUTES as $key) {
            putenv($key);
        }
    }

    private function write(string $contents): string
    {
        file_put_contents($this->envPath, $contents);

        return $this->envPath;
    }

    private function minimal(string $extra = ''): Config
    {
        return Config::fromEnvironment($this->write(<<<ENV
            DISCORD_TOKEN=discord-token
            TWITCH_CLIENT_ID=client-id
            TWITCH_NICK=relaybot
            {$extra}
            ENV), '/tmp/store.json');
    }

    public function testReadsSettingsFromTheFile(): void
    {
        $config = $this->minimal();

        self::assertSame('discord-token', $config->discordToken);
        self::assertSame('client-id', $config->twitchClientId);
        self::assertSame('relaybot', $config->twitchNick);
    }

    /**
     * The bot runs without a client secret — Helix sends only the bearer token
     * and Client-Id, and IRC needs neither. Only *refreshing* breaks.
     */
    public function testClientSecretIsOptional(): void
    {
        $config = $this->minimal();

        self::assertSame('', $config->twitchClientSecret);
        self::assertFalse($config->canRefreshTwitchToken());
    }

    public function testClientSecretEnablesRefresh(): void
    {
        self::assertTrue($this->minimal('TWITCH_CLIENT_SECRET=shh')->canRefreshTwitchToken());
    }

    public function testAnEmptyClientSecretCountsAsAbsent(): void
    {
        self::assertFalse($this->minimal('TWITCH_CLIENT_SECRET=')->canRefreshTwitchToken());
    }

    /**
     * With no owner configured, nobody can reach `api`. That is the intended
     * default — it reaches every endpoint with the bot's own token.
     */
    public function testNoOwnerByDefault(): void
    {
        $config = $this->minimal();

        self::assertNull($config->discordOwnerId);
        self::assertNull($config->twitchOwnerLogin);
        self::assertFalse($config->hasOwner());
    }

    public function testEitherOwnerIdIsEnough(): void
    {
        self::assertTrue($this->minimal('DISCORD_OWNER_ID=42')->hasOwner());
        self::assertTrue($this->minimal('TWITCH_OWNER_LOGIN=valgorithms')->hasOwner());
    }

    /** Twitch logins are lower-case; comparing them raw would miss. */
    public function testTwitchOwnerLoginIsNormalised(): void
    {
        self::assertSame('valgorithms', $this->minimal('TWITCH_OWNER_LOGIN=ValGorithms')->twitchOwnerLogin);
    }

    public function testPrefixesDefaultToBang(): void
    {
        $config = $this->minimal();

        self::assertSame('!', $config->discordPrefix);
        self::assertSame('!', $config->twitchPrefix);
    }

    /** The two chats often want different prefixes; another bot may own `!`. */
    public function testPrefixesAreIndependent(): void
    {
        $config = $this->minimal("DISCORD_PREFIX=?\nTWITCH_PREFIX=~");

        self::assertSame('?', $config->discordPrefix);
        self::assertSame('~', $config->twitchPrefix);
    }

    public function testLogLevelDefaultsToInfo(): void
    {
        self::assertSame('info', $this->minimal()->logLevel);
        self::assertSame('debug', $this->minimal('LOG_LEVEL=DEBUG')->logLevel);
    }

    #[DataProvider('requiredKeys')]
    public function testMissingRequiredSettingIsReportedByName(string $omit): void
    {
        $lines = array_values(array_filter([
            'DISCORD_TOKEN=discord-token',
            'TWITCH_CLIENT_ID=client-id',
            'TWITCH_NICK=relaybot',
        ], static fn (string $l): bool => ! str_starts_with($l, $omit . '=')));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($omit, '/') . '/');

        Config::fromEnvironment($this->write(implode("\n", $lines)), '/tmp/store.json');
    }

    public static function requiredKeys(): array
    {
        return [['DISCORD_TOKEN'], ['TWITCH_CLIENT_ID'], ['TWITCH_NICK']];
    }

    public function testCommentsAndBlankLinesAreIgnored(): void
    {
        $config = Config::fromEnvironment($this->write(<<<'ENV'
            # a comment

            DISCORD_TOKEN=discord-token
            TWITCH_CLIENT_ID=client-id
            TWITCH_NICK=relaybot
            ENV), '/tmp/store.json');

        self::assertSame('discord-token', $config->discordToken);
    }

    public function testQuotedValuesAreUnwrapped(): void
    {
        $config = Config::fromEnvironment($this->write(<<<'ENV'
            DISCORD_TOKEN="quoted-token"
            TWITCH_CLIENT_ID='single'
            TWITCH_NICK=relaybot
            ENV), '/tmp/store.json');

        self::assertSame('quoted-token', $config->discordToken);
        self::assertSame('single', $config->twitchClientId);
    }

    /** A container should be able to override the file without editing it. */
    public function testProcessEnvironmentBeatsTheFile(): void
    {
        putenv('TWITCH_NICK=from-environment');

        self::assertSame('from-environment', $this->minimal()->twitchNick);
    }

    public function testMissingFileStillReadsTheEnvironment(): void
    {
        putenv('DISCORD_TOKEN=d');
        putenv('TWITCH_CLIENT_ID=c');
        putenv('TWITCH_NICK=n');

        $config = Config::fromEnvironment($this->envPath . '.nope', '/tmp/store.json');

        self::assertSame('n', $config->twitchNick);
    }
}
