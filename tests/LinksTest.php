<?php

namespace TwitchBot\Tests;

use PHPUnit\Framework\TestCase;
use TwitchBot\Links;

final class LinksTest extends TestCase
{
    private function links(): Links
    {
        return new Links([
            '100' => ['1' => 'twitchdev', '2' => 'ninja'],
            '200' => ['3' => 'twitchdev'],
        ]);
    }

    public function testDiscordChannelMapsToOneTwitchChannel(): void
    {
        self::assertSame('twitchdev', $this->links()->twitchFor('1'));
        self::assertSame('ninja', $this->links()->twitchFor('2'));
    }

    public function testUnbridgedChannelMapsToNothing(): void
    {
        self::assertNull($this->links()->twitchFor('999'));
    }

    /**
     * The asymmetry that matters: several unrelated servers may follow the
     * same streamer, so one Twitch message fans out to all of them.
     */
    public function testOneTwitchChannelFansOutAcrossGuilds(): void
    {
        self::assertSame(['1', '3'], $this->links()->discordFor('twitchdev'));
        self::assertSame(['2'], $this->links()->discordFor('ninja'));
    }

    public function testFanOutIsCaseInsensitive(): void
    {
        self::assertSame(['1', '3'], $this->links()->discordFor('TwitchDev'));
    }

    /** Two guilds following one streamer must share a single JOIN. */
    public function testLoginsAreDeduplicated(): void
    {
        self::assertSame(['ninja', 'twitchdev'], $this->links()->logins());
    }

    public function testForGuildReturnsOnlyThatGuild(): void
    {
        self::assertSame(['3' => 'twitchdev'], $this->links()->forGuild('200'));
        self::assertSame([], $this->links()->forGuild('nope'));
    }

    public function testCounting(): void
    {
        self::assertSame(3, $this->links()->count());
        self::assertFalse($this->links()->isEmpty());
        self::assertTrue((new Links())->isEmpty());
    }

    // ── join/part diffing ────────────────────────────────────────────────

    public function testDiffJoinsOnlyWhatIsNew(): void
    {
        $next = new Links(['100' => ['1' => 'twitchdev', '2' => 'ninja'], '200' => ['3' => 'twitchdev'], '300' => ['4' => 'shroud']]);

        self::assertSame(['join' => ['shroud'], 'part' => []], $this->links()->diff($next));
    }

    public function testDiffPartsOnlyWhatIsGone(): void
    {
        $next = new Links(['100' => ['1' => 'twitchdev'], '200' => ['3' => 'twitchdev']]);

        self::assertSame(['join' => [], 'part' => ['ninja']], $this->links()->diff($next));
    }

    /**
     * Removing one guild's link to a shared streamer must NOT part the
     * channel, because another guild is still following it.
     */
    public function testDroppingOneGuildDoesNotPartAChannelAnotherGuildStillFollows(): void
    {
        $next = new Links(['100' => ['1' => 'twitchdev', '2' => 'ninja']]);

        self::assertSame(['join' => [], 'part' => []], $this->links()->diff($next));
    }

    public function testDiffToEmptyPartsEverything(): void
    {
        self::assertSame(['join' => [], 'part' => ['ninja', 'twitchdev']], $this->links()->diff(new Links()));
    }
}
