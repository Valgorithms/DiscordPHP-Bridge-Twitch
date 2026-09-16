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

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use TwitchBot\Actions\ApiActions;
use TwitchBot\Actions\ChannelActions;
use TwitchBot\Actions\HelpActions;
use TwitchBot\Actions\ModerationActions;
use TwitchBot\Actions\RelayActions;
use TwitchBot\Actions\StreamActions;
use TwitchBot\Bot;
use TwitchBot\Config;
use TwitchBot\Store;

// Walk up for the autoloader so a PHPacker binary, which runs from a different
// directory than the sources, still finds it.
$baseDir = __DIR__;
while (! is_file($baseDir . '/vendor/autoload.php')) {
    $parent = \dirname($baseDir);

    if ($parent === $baseDir) {
        fwrite(STDERR, "Could not find vendor/autoload.php — run `composer install`.\n");

        exit(1);
    }

    $baseDir = $parent;
}

require $baseDir . '/vendor/autoload.php';

try {
    $config = Config::fromEnvironment($baseDir . '/.env', $baseDir . '/storage/bridges.json');
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");

    exit(1);
}

$logger = new Logger('twitchbot');
$handler = new StreamHandler('php://stdout', Level::fromName($config->logLevel));
$handler->setFormatter(new LineFormatter("[%datetime%] %level_name%: %message%\n", 'H:i:s', true, true));
$logger->pushHandler($handler);

$bot = new Bot($config, new Store($config->storePath), ['logger' => $logger]);

// One catalogue, registered into both chat clients once each is up. Order is
// only cosmetic — it decides the order of the groups in `help`.
$bot->getActions()
    ->addAll(new HelpActions())
    ->addAll(new RelayActions())
    ->addAll(new ChannelActions())
    ->addAll(new StreamActions())
    ->addAll(new ModerationActions())
    ->addAll(new ApiActions());

if (! $config->hasOwner()) {
    $logger->warning(
        'no DISCORD_OWNER_ID or TWITCH_OWNER_LOGIN set — the `api` command is unreachable. '
        . 'That is the safe default; set one to enable it.',
    );
}

$logger->info(sprintf('starting with %d actions', $bot->getActions()->count()));

$bot->run();
