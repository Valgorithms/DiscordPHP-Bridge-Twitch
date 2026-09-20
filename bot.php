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
use Monolog\LogRecord;
use TwitchBot\Actions\ApiActions;
use TwitchBot\Actions\ChannelActions;
use TwitchBot\Actions\HelpActions;
use TwitchBot\Actions\ModerationActions;
use TwitchBot\Actions\RelayActions;
use TwitchBot\Actions\StreamActions;
use TwitchBot\Bot;
use TwitchBot\Config;
use TwitchBot\Store;
use TwitchBot\Support\Filesystem;
use TwitchBot\Support\GatewayDiagnostics;

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

// `%context%` matters more than it looks. DiscordPHP reports a fatal gateway
// close as the message "not reconnecting - critical op code" with the code
// itself in the context — so a format without it prints a line that says the
// bot stopped and nothing about why.
$handler->setFormatter(new LineFormatter("[%datetime%] %level_name%: %message% %context%\n", 'H:i:s', true, true));
$logger->pushHandler($handler);

// Watch for that close and explain it. There is no event to listen for; the
// library only ever reports the code by logging it, so this reads it back off
// the record and prints the fix.
$logger->pushProcessor(static function (LogRecord $record) use ($logger): LogRecord {
    $op = GatewayDiagnostics::fromLogContext($record->message, $record->context);

    if ($op !== null) {
        // Straight to stderr rather than through the logger, which would
        // re-enter this processor, and so that it is visible at any log level.
        fwrite(STDERR, GatewayDiagnostics::report($op, (string) ($record->context['reason'] ?? '')));
    }

    return $record;
});

// One filesystem for the process: asynchronous where the platform has
// ext-uv or ext-eio, durable and blocking where it does not.
$bot = new Bot($config, new Store($config->storePath, Filesystem::create()), ['logger' => $logger]);

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

// Ctrl-C has to put a queued write on the disk: once the loop stops, it
// would never run.
foreach ([\defined('SIGINT') ? SIGINT : null, \defined('SIGTERM') ? SIGTERM : null] as $signal) {
    if ($signal !== null && function_exists('pcntl_signal')) {
        $bot->getLoop()->addSignal($signal, static function () use ($bot, $logger): void {
            $logger->info('[bot] shutting down');
            $bot->getStore()->flush();
            $bot->getTwitch()->close();
            $bot->close();
        });
    }
}

$bot->run();
