<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-TwitchBot project.
 *
 * Copyright (c) 2026-present Valithor Obsidion <valithor@valgorithms.com>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace TwitchBot\Tests;

use PHPUnit\Framework\TestCase;
use TwitchBot\Store;

/**
 * The configuration has to survive a restart — it is the only record of what
 * ``relay link`` was told, and losing it means every admin has to do it again.
 */
final class StoreRestartTest extends TestCase
{
    private string $dir;

    private string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/twitchbot-restart-' . bin2hex(random_bytes(6));
        $this->path = $this->dir . '/bridges.json';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    public function testEveryGuildsBridgesComeBackAfterARestart(): void
    {
        $before = new Store($this->path);
        $before->link('guild1', 'chan1', 'twitchdev');
        $before->link('guild1', 'chan2', 'someone');
        $before->link('guild2', 'chan3', 'twitchdev');

        $after = $this->restart();

        $this->assertSame(3, $after->links()->count());
        $this->assertSame('twitchdev', $after->links()->twitchFor('chan1'));
        $this->assertSame(['chan1', 'chan3'], $after->links()->discordFor('twitchdev'));
        $this->assertSame([], $after->warnings());
    }

    public function testTheChannelsToRejoinAreRestoredDeduplicated(): void
    {
        // This list is what the Twitch connection is constructed with and what
        // the gateway syncs to, so a restart rejoins exactly what was bridged.
        $before = new Store($this->path);
        $before->link('guild1', 'chan1', 'twitchdev');
        $before->link('guild2', 'chan2', 'twitchdev');
        $before->link('guild2', 'chan3', 'someone');

        $this->assertSame(['someone', 'twitchdev'], $this->restart()->links()->logins());
    }

    public function testAnUnsetSurvivesTooRatherThanComingBack(): void
    {
        $before = new Store($this->path);
        $before->link('guild1', 'chan1', 'twitchdev');
        $before->link('guild1', 'chan2', 'someone');
        $before->unlink('guild1', 'chan1');

        $after = $this->restart();

        $this->assertNull($after->links()->twitchFor('chan1'));
        $this->assertSame('someone', $after->links()->twitchFor('chan2'));
    }

    public function testABackupIsKeptBesideTheConfiguration(): void
    {
        (new Store($this->path))->link('guild1', 'chan1', 'twitchdev');

        $this->assertFileExists($this->path . Store::BACKUP_SUFFIX);
        $this->assertSame(
            file_get_contents($this->path),
            file_get_contents($this->path . Store::BACKUP_SUFFIX),
            'the backup should be the state that was just saved, not the one before it',
        );
    }

    public function testADamagedFileIsRecoveredFromTheBackup(): void
    {
        $before = new Store($this->path);
        $before->link('guild1', 'chan1', 'twitchdev');
        $before->link('guild2', 'chan2', 'someone');

        // Truncated mid-write by something outside this process.
        file_put_contents($this->path, '{"links": {"guild1": {"chan1": "twitch');

        $after = $this->restart();

        $this->assertSame(2, $after->links()->count());
        $this->assertSame('someone', $after->links()->twitchFor('chan2'));
        $this->assertStringContainsString('recovered', $after->warnings()[0] ?? '');
    }

    public function testAnUnreadableFileIsKeptRatherThanOverwritten(): void
    {
        (new Store($this->path))->link('guild1', 'chan1', 'twitchdev');

        file_put_contents($this->path, 'not json');
        file_put_contents($this->path . Store::BACKUP_SUFFIX, 'not json either');

        $after = $this->restart();
        $after->link('guild9', 'chan9', 'somebodyelse');

        $kept = glob($this->dir . '/bridges.json.corrupt-*') ?: [];

        $this->assertCount(1, $kept);
        $this->assertSame('not json', file_get_contents($kept[0]));
        $this->assertStringContainsString('kept as', $after->warnings()[0] ?? '');
    }

    public function testAnEmptyFileIsNotTreatedAsDamage(): void
    {
        @mkdir($this->dir, 0o777, true);
        file_put_contents($this->path, '');

        $store = new Store($this->path);

        $this->assertTrue($store->links()->isEmpty());
        $this->assertSame([], $store->warnings());
    }

    public function testAHandEditedFileCannotTakeTheBridgeDown(): void
    {
        @mkdir($this->dir, 0o777, true);
        file_put_contents($this->path, (string) json_encode([
            'links' => [
                'guild1' => ['chan1' => 'TwitchDev', 'chan2' => ['not' => 'a login'], 'chan3' => ''],
                'guild2' => 'not a list of channels',
            ],
        ]));

        $store = new Store($this->path);

        // Logins are lower-case everywhere else, including in a hand-edited file.
        $this->assertSame(['chan1' => 'twitchdev'], $store->links()->forGuild('guild1'));
        $this->assertSame([], $store->links()->forGuild('guild2'));
        $this->assertCount(3, $store->warnings());
    }

    public function testTheGoodEntriesOfAHandEditedFileAreNotLostOnTheNextWrite(): void
    {
        @mkdir($this->dir, 0o777, true);
        file_put_contents($this->path, (string) json_encode([
            'links' => ['guild1' => ['chan1' => 'twitchdev', 'chan2' => ['broken']]],
        ]));

        $store = new Store($this->path);
        $store->link('guild2', 'chan3', 'someone');

        $this->assertSame('twitchdev', $this->restart()->links()->twitchFor('chan1'));
    }

    public function testStaleTempFilesFromAKilledProcessAreCleanedUp(): void
    {
        @mkdir($this->dir, 0o777, true);
        file_put_contents($this->path . '.999999.tmp', '{}');
        file_put_contents($this->path . '.999999.bak.tmp', '{}');

        new Store($this->path);

        $this->assertSame([], glob($this->dir . '/*.tmp'));
    }

    public function testTheStorePathIsReportableForTheStartupLine(): void
    {
        $this->assertSame($this->path, (new Store($this->path))->path());
    }

    /** Re-opens the same file the way a restarted process would. */
    private function restart(): Store
    {
        return new Store($this->path);
    }
}
