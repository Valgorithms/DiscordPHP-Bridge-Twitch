<?php

declare(strict_types=1);

namespace TwitchBot\Tests;

use PHPUnit\Framework\TestCase;
use TwitchBot\Bot;

/**
 * Constants that leave the process.
 *
 * {@see Bot::GITHUB} is printed into public chat by `about`, so a wrong value
 * is not a stale comment — it is a dead link handed to viewers. It shipped
 * wrong once, naming an organisation the repository had never been under, and
 * nothing caught it because nothing was looking.
 */
final class BotConstantsTest extends TestCase
{
    public function testGithubUrlIsWellFormed(): void
    {
        self::assertMatchesRegularExpression(
            '#^https://github\.com/[\w.-]+/[\w.-]+$#',
            Bot::GITHUB,
            'about prints this into chat; it has to be a plain repository URL',
        );
    }

    /**
     * The constant must name the repository this actually is.
     *
     * Checked against the checkout's own origin remote, which is the only
     * source of truth available without a network call. Skipped when there is
     * no remote to compare against — a released tarball, or a fresh clone
     * before one is added — because the constant is still correct there and a
     * failure would be noise.
     */
    public function testGithubUrlMatchesTheOriginRemote(): void
    {
        $config = \dirname(__DIR__) . '/.git/config';

        if (! is_file($config)) {
            self::markTestSkipped('not a git checkout');
        }

        $contents = (string) file_get_contents($config);

        if (preg_match('#url\s*=\s*(\S*github\.com\S+)#i', $contents, $matches) !== 1) {
            self::markTestSkipped('no github remote configured');
        }

        // Normalise both to owner/name: the remote may be SSH or carry .git,
        // and neither difference is a mismatch.
        $remote = $this->ownerAndName($matches[1]);
        $declared = $this->ownerAndName(Bot::GITHUB);

        self::assertSame(
            $remote,
            $declared,
            sprintf('Bot::GITHUB says %s but origin is %s', $declared, $remote),
        );
    }

    private function ownerAndName(string $url): string
    {
        $url = preg_replace('#\.git$#', '', trim($url)) ?? $url;
        $url = preg_replace('#^(https?://|git@)#', '', $url) ?? $url;
        $url = str_replace('github.com:', 'github.com/', $url);

        $parts = explode('/', $url);
        $name = array_pop($parts) ?? '';
        $owner = array_pop($parts) ?? '';

        return strtolower($owner . '/' . $name);
    }
}
