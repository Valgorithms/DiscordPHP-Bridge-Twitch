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

namespace TwitchBot;

use Discord\MessageCommandClient;
use Discord\Parts\Channel\Channel;
use Discord\WebSockets\Intents;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

use Twitch\Auth\DeviceCodeReauthorizer;
use Twitch\Auth\EnvFileTokenStore;
use Twitch\Chat\CommandClient as TwitchCommandClient;
use Twitch\Http\OAuth;
use Twitch\Twitch;
use TwitchBot\Api\RepositoryDispatcher;
use TwitchBot\Command\ActionRegistry;
use TwitchBot\Command\DiscordAdapter;
use TwitchBot\Command\SlashAdapter;
use TwitchBot\Command\TwitchAdapter;
use TwitchBot\Relay\ChatRelay;
use TwitchBot\Relay\TwitchGateway;
use TwitchBot\Relay\WebhookDelivery;
use TwitchBot\Support\BridgeCheck;

/**
 * The bot: DiscordPHP's `MessageCommandClient` and TwitchPHP's `CommandClient`
 * on one ReactPHP loop, driven by one catalogue of actions.
 *
 * Extending `MessageCommandClient` rather than owning one is what lets the
 * Discord side keep everything that class already provides — prefix handling,
 * aliases, cooldowns, the command registry — while the Twitch side gets the
 * equivalent from `Twitch\Chat\CommandClient`. Neither knows about the other:
 * both are fed from {@see ActionRegistry}, and a command written once appears
 * in both chats.
 *
 * `Twitch::run()` would call `Loop::run()` itself, so the Twitch client is
 * started through `bootstrap()` and `Discord::run()` is left to drive the loop.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
class Bot extends MessageCommandClient
{
    public const GITHUB = 'https://github.com/Valgorithms/DiscordPHP-TwitchBot';

    /** MESSAGE_CONTENT is privileged; without it every relayed message is empty. */
    public const INTENTS = Intents::GUILDS | Intents::GUILD_MESSAGES | Intents::MESSAGE_CONTENT;

    /**
     * Exactly the scopes the built-in commands need — no more.
     *
     * Each line names what stops working without it. Nothing is requested
     * speculatively: every extra scope makes the consent screen longer and
     * scarier, and grants the bot authority it has no code to use.
     *
     * Two families, and the difference decides what the bot can act on:
     *
     * - `channel:*` requires the token to belong to the **broadcaster**, or to
     *   an account they have added as a channel editor. These only ever work on
     *   the channel that authorized the bot.
     * - `moderator:*` works on any channel where the bot account is a
     *   **moderator**, which is what lets one bot moderate many channels.
     *
     * So `title`, `vip`, `mod`, `raid` and `commercial` act on the authorizing
     * account's own channel, while `ban`, `timeout`, `clear`, `announce` and
     * the chat modes work anywhere the bot is modded.
     *
     * {@see ApiActions} can reach endpoints beyond this list. That is
     * deliberate and not a reason to widen it: an uncovered call fails with a
     * {@see \Twitch\Exceptions\MissingScopeException} naming the scope it
     * wanted, which is a better outcome than holding every permission on the
     * chance somebody types one.
     *
     * Verified against the Twitch OpenAPI description rather than the docs
     * pages. {@link https://github.com/DmitryScaletta/twitch-api-swagger}
     *
     * @var list<string>
     */
    public const TWITCH_SCOPES = [
        // Reading and speaking in chat: the relay, and every command reply.
        'chat:read',
        'chat:edit',

        // `title`, `game`, `tags`, and `marker`.
        'channel:manage:broadcast',

        // `followers`.
        'moderator:read:followers',

        // `clip`.
        'clips:edit',

        // `ban`, `unban`, `timeout`.
        'moderator:manage:banned_users',

        // `clear`.
        'moderator:manage:chat_messages',

        // `announce`.
        'moderator:manage:announcements',

        // `shoutout`.
        'moderator:manage:shoutouts',

        // `slow`, `subonly`, `emoteonly`, `followersonly`.
        'moderator:manage:chat_settings',

        // `vip`, `unvip`.
        'channel:manage:vips',

        // `mod`, `unmod`.
        'channel:manage:moderators',

        // `raid`, `unraid`.
        'channel:manage:raids',

        // `commercial`.
        'channel:edit:commercial',
    ];

    private readonly Twitch $twitch;

    private readonly ActionRegistry $actions;

    private readonly RepositoryDispatcher $dispatcher;

    private ?TwitchCommandClient $twitchCommands = null;

    /** Named `twitchGateway`, not `gateway`: {@see \Discord\Discord} already owns that name. */
    private ?TwitchGateway $twitchGateway = null;

    private ?WebhookDelivery $delivery = null;

    /**
     * How long after startup to check the restored bridges.
     *
     * Long enough for the guild caches to fill and the JOINs to land.
     */
    public const BRIDGE_CHECK_DELAY = 10.0;

    private bool $started = false;

    /** @var array<string, array<string, mixed>|null> Twitch login => cached user row, negatives included. */
    private array $twitchUsers = [];

    public function __construct(
        private readonly Config $config,
        private readonly Store $store,
        array $options = [],
    ) {
        parent::__construct($options + [
            'token' => $config->discordToken,
            'intents' => self::INTENTS,
            'prefix' => $config->discordPrefix,
            'caseInsensitiveCommands' => true,
            // Replaced by the cross-platform `help` action, which lists what
            // the asker may actually run and is worded the same on both sides.
            'defaultHelpCommand' => false,
        ]);

        $this->actions = new ActionRegistry();

        $this->twitch = new Twitch([
            'client_id' => $config->twitchClientId,
            'client_secret' => $config->twitchClientSecret,
            'token' => $config->twitchToken,
            'refresh_token' => $config->twitchRefreshToken,
            'nick' => $config->twitchNick,
            'channels' => $store->links()->logins(),
            'command_prefix' => $config->twitchPrefix,
            'loop' => $this->getLoop(),
            'logger' => $this->logger,
            // Twitch rotates the refresh token on every refresh; without this
            // the bot locks itself out of its own account on restart.
            'token_store' => new EnvFileTokenStore($config->envPath),
            // Last resort when refreshing cannot recover the grant — which is
            // always, when no client secret is configured.
            'reauthorize' => $this->reauthorizer(),
        ]);

        $this->dispatcher = new RepositoryDispatcher($this->twitch);

        $this->once('init', fn () => $this->start());
    }

    // ── Accessors ──────────────────────────────────────────────────────

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function getStore(): Store
    {
        return $this->store;
    }

    public function getTwitch(): Twitch
    {
        return $this->twitch;
    }

    public function getActions(): ActionRegistry
    {
        return $this->actions;
    }

    public function getDispatcher(): RepositoryDispatcher
    {
        return $this->dispatcher;
    }

    /** @throws \LogicException before the Twitch connection is up. */
    public function twitchGateway(): TwitchGateway
    {
        return $this->twitchGateway
            ?? throw new \LogicException('The Twitch gateway is not up yet.');
    }

    /** The gateway, or `null` when Twitch has not connected — for callers that can cope. */
    public function twitchGatewayOrNull(): ?TwitchGateway
    {
        return $this->twitchGateway;
    }

    public function delivery(): WebhookDelivery
    {
        return $this->delivery ??= new WebhookDelivery($this);
    }

    // ── Twitch helpers ─────────────────────────────────────────────────

    /**
     * Brings the Twitch connection's joined channels in line with the routing
     * table. Safe to call before Twitch is up; it becomes a no-op.
     */
    public function syncTwitchChannels(Links $links): void
    {
        if ($this->twitchGateway === null) {
            return;
        }

        $changed = $this->twitchGateway->sync($links);

        if ($changed['join'] !== [] || $changed['part'] !== []) {
            $this->logger->info(sprintf(
                '[bot] channels synced (+%d / -%d), now following %d',
                count($changed['join']),
                count($changed['part']),
                count($links->logins()),
            ));
        }
    }

    /**
     * Looks up a Twitch user by login, cached — including negative results, so
     * a typo'd channel is not re-queried on every relayed message.
     *
     * @return PromiseInterface<array<string, mixed>|null>
     */
    public function resolveTwitchUser(string $login): PromiseInterface
    {
        $login = strtolower($login);

        if (array_key_exists($login, $this->twitchUsers)) {
            return resolve($this->twitchUsers[$login]);
        }

        return $this->twitch->users->fetchByLogin($login)->then(
            function ($user) use ($login): ?array {
                $row = $user === null ? null : [
                    'id' => (string) $user->id,
                    'login' => (string) $user->login,
                    'display_name' => (string) $user->display_name,
                    'avatar' => (string) ($user->profile_image_url ?? ''),
                ];

                return $this->twitchUsers[$login] = $row;
            },
            function (\Throwable $e) use ($login): ?array {
                // A failed lookup is not proof the channel is absent, so it is
                // not cached as one — but it must not break delivery either.
                $this->logger->debug('[bot] twitch user lookup failed for ' . $login . ': ' . $e->getMessage());

                return null;
            },
        );
    }

    /** @return PromiseInterface<string|null> */
    public function resolveTwitchAvatar(string $login): PromiseInterface
    {
        return $this->resolveTwitchUser($login)->then(
            static fn (?array $user) => ($user['avatar'] ?? '') !== '' ? $user['avatar'] : null,
        );
    }

    // ── Startup ────────────────────────────────────────────────────────

    /**
     * Brings Twitch up once Discord is ready, then registers commands.
     *
     * Registration happens *after* Twitch connects, because the Twitch command
     * client needs a live client to listen on. If Twitch never comes up, the
     * Discord half still registers: an operator has to be able to ask what went
     * wrong from somewhere.
     */
    private function start(): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;

        $this->twitch->bootstrap()->then(
            function (): void {
                $this->twitchGateway = new TwitchGateway(
                    $this->twitch,
                    $this->getLoop(),
                    $this->logger,
                    $this->config->twitchNick,
                );
                $this->twitchGateway->listen();

                $this->twitchCommands = new TwitchCommandClient($this->twitch, ['help' => false]);

                $this->logger->info(sprintf(
                    '[bot] twitch ready as %s; %d bridge(s) configured',
                    (string) $this->twitch->getLogin(),
                    $this->store->links()->count(),
                ));

                $this->warnIfCannotRefresh();

                $this->registerDiscord();
                (new TwitchAdapter($this, $this->actions, $this->twitchCommands))->register();
                (new ChatRelay($this))->attach();

                $this->syncTwitchChannels($this->store->links());
                $this->reportRestoredBridges();

                $this->logger->info(sprintf(
                    '[bot] %d actions registered on both surfaces',
                    $this->actions->count(),
                ));
            },
            function (\Throwable $e): void {
                $this->logger->error('[bot] twitch failed to start: ' . $e->getMessage());
                $this->logger->warning('[bot] running Discord-only; relay and Twitch commands are unavailable');

                $this->registerDiscord();
                $this->reportRestoredBridges();
            },
        );
    }

    /**
     * Reports what was restored from disk, and whether it still works.
     *
     * Configuration outlives the process, and both ends of a bridge can stop
     * working while the bot is down — a Discord channel deleted, the bot
     * removed from the server, a streamer renamed. None of that produces an
     * error at startup; it produces a bridge that quietly relays nothing,
     * which looks exactly like a quiet day.
     *
     * Nothing is pruned: a guild can be briefly unavailable during a Discord
     * outage, and deleting somebody's configuration over a bad ten seconds
     * would be far worse than saying so.
     */
    private function reportRestoredBridges(): void
    {
        $links = $this->store->links();

        $this->logger->info('[bot] ' . BridgeCheck::restored(
            $links->count(),
            count($links->guilds()),
            $this->store->path(),
        ));

        // Whether saves actually happen off the loop is a property of the
        // host, not of this build, so it is worth one line at every start.
        $this->logger->info('[bot] ' . $this->store->filesystem()->describe());

        // A file that could not be read needs somebody's attention now: the
        // bot is running with less configuration than it was given.
        if ($this->store->warnings() !== []) {
            foreach ($this->store->warnings() as $warning) {
                $this->logger->warning('[bot] ' . $warning);
            }

            $this->notifyOwner(
                "⚠️ **I had trouble reading my bridge configuration.**\n- "
                . implode("\n- ", $this->store->warnings()),
            );
        }

        if ($links->isEmpty()) {
            return;
        }

        // Late enough that the guild caches have settled and the JOINs have
        // had their chance; probing immediately would report both as broken.
        $this->getLoop()->addTimer(self::BRIDGE_CHECK_DELAY, fn () => $this->verifyBridges());
    }

    /** Resolves every distinct Twitch channel, then reports on each bridge. */
    private function verifyBridges(): void
    {
        $probes = [];

        foreach ($this->store->links()->logins() as $login) {
            $probes[$login] = $this->resolveTwitchUser($login)->then(
                static fn (?array $user): bool => $user !== null,
                // A lookup that failed is not proof the channel is gone.
                static fn (): bool => true,
            );
        }

        ($probes === [] ? resolve([]) : all($probes))->then(function (array $exists): void {
            $links = $this->store->links();
            $joined = $this->twitchGateway?->joined() ?? $links->logins();
            $rows = [];

            foreach ($links->guilds() as $guildId) {
                foreach ($links->forGuild($guildId) as $channelId => $login) {
                    $channelId = (string) $channelId;
                    $login = (string) $login;

                    $rows[] = [
                        'channel_id' => $channelId,
                        'login' => $login,
                        'channel_ok' => $this->getChannel($channelId) instanceof Channel,
                        'twitch_ok' => $exists[$login] ?? true,
                        'joined' => in_array($login, $joined, true),
                    ];
                }
            }

            $summary = BridgeCheck::summarise($rows);
            $headline = sprintf(
                '%d of %d bridge%s working',
                $summary['healthy'],
                count($rows),
                count($rows) === 1 ? '' : 's',
            );

            if ($summary['problems'] === []) {
                $this->logger->info('[bot] ' . $headline);

                return;
            }

            $this->logger->warning('[bot] ' . $headline);

            foreach ($summary['problems'] as $problem) {
                $this->logger->warning('[bot] ' . $problem);
            }

            $this->notifyOwner(
                sprintf("⚠️ **%s.**\n- ", $headline) . implode("\n- ", $summary['problems'])
                . "\n-# Nothing has been removed — use `relay list` to fix or unlink these.",
            );
        });
    }

    /**
     * Tells a human, when one is configured, rather than only the log.
     *
     * A bot that needs attention and only whispers it into a logfile stays
     * broken until somebody happens to look.
     */
    public function notifyOwner(string $markdown): void
    {
        $ownerId = $this->config->discordOwnerId;

        if ($ownerId === null || $ownerId === '') {
            return;
        }

        $this->users->fetch($ownerId)->then(
            fn ($user) => $user->sendMessage($markdown),
        )->then(null, fn (\Throwable $e) => $this->logger->error(
            '[bot] could not DM the owner: ' . $e->getMessage(),
        ));
    }
    /**
     * Both Discord forms of every action: prefix commands, and slash commands
     * for the actions that declare one.
     *
     * Registered together and exactly once, on either path out of `start()`, so
     * a Twitch failure costs the Twitch half and nothing else.
     */
    private function registerDiscord(): void
    {
        (new DiscordAdapter($this, $this->actions))->register();
        (new SlashAdapter($this, $this->actions))->register();
    }

    private function warnIfCannotRefresh(): void
    {
        if ($this->config->canRefreshTwitchToken()) {
            return;
        }

        // Say this at startup, not in four hours when the token dies and
        // somebody has to work out why chat went quiet.
        $this->logger->warning(
            '[bot] no TWITCH_CLIENT_SECRET — the access token cannot be refreshed. '
            . 'When it expires (~4h) the bot will ask for a device code instead. '
            . 'Set a client secret for unattended operation.',
        );
    }

    /**
     * The device-code reauthorizer, and the prompt that reaches a human.
     *
     * Device-code is the one grant `OAuth::form()` allows without a client
     * secret, so this is the only route back to a working token when none is
     * configured.
     *
     * The prompt goes two places: the log, always, and a DM to
     * `DISCORD_OWNER_ID` when one is set. A bot that needs re-authorization and
     * only whispers it into a logfile stays down until somebody looks.
     */
    private function reauthorizer(): DeviceCodeReauthorizer
    {
        $oauth = new OAuth(
            $this->config->twitchClientId,
            $this->config->twitchClientSecret,
            $this->getLoop(),
        );

        return new DeviceCodeReauthorizer(
            $oauth,
            self::TWITCH_SCOPES,
            fn (array $device) => $this->promptForDeviceCode($device),
            $this->getLoop(),
        );
    }

    /** @param array<string, mixed> $device */
    private function promptForDeviceCode(array $device): void
    {
        $uri = (string) ($device['verification_uri'] ?? 'https://www.twitch.tv/activate');
        $code = (string) ($device['user_code'] ?? '?');
        $expires = (int) ($device['expires_in'] ?? 1800);

        $this->logger->warning(sprintf(
            "[bot] TWITCH RE-AUTHORIZATION NEEDED — the bot is degraded until this is approved.\n"
            . "        Open %s and enter %s\n"
            . '        (expires in %ds)',
            $uri,
            $code,
            $expires,
        ));

        $this->notifyOwner(sprintf(
            "⚠️ **Twitch needs re-authorizing.**\nOpen <%s> and enter `%s` — it expires in %d minutes.",
            $uri,
            $code,
            (int) round($expires / 60),
        ));
    }
}
