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
use TwitchBot\Command\TwitchAdapter;
use TwitchBot\Relay\ChatRelay;
use TwitchBot\Relay\TwitchGateway;
use TwitchBot\Relay\WebhookDelivery;

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
     * Scopes requested when re-authorizing by device code.
     *
     * Wider than the relay alone needs, because the API commands act on the
     * broadcaster's channel: editing the title and category needs
     * `channel:manage:broadcast`, and the moderation commands need their own.
     * A narrower grant still runs — the endpoints it does not cover fail with a
     * missing-scope error naming what is absent.
     *
     * @var list<string>
     */
    public const TWITCH_SCOPES = [
        'chat:read',
        'chat:edit',
        'channel:manage:broadcast',
        'channel:read:subscriptions',
        'moderator:manage:banned_users',
        'moderator:manage:chat_messages',
        'moderator:manage:chat_settings',
        'moderator:read:followers',
        'clips:edit',
    ];

    private readonly Twitch $twitch;

    private readonly ActionRegistry $actions;

    private readonly RepositoryDispatcher $dispatcher;

    private ?TwitchCommandClient $twitchCommands = null;

    /** Named `twitchGateway`, not `gateway`: {@see \Discord\Discord} already owns that name. */
    private ?TwitchGateway $twitchGateway = null;

    private ?WebhookDelivery $delivery = null;

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

                (new DiscordAdapter($this, $this->actions))->register();
                (new TwitchAdapter($this, $this->actions, $this->twitchCommands))->register();
                (new ChatRelay($this))->attach();

                $this->syncTwitchChannels($this->store->links());

                $this->logger->info(sprintf(
                    '[bot] %d actions registered on both surfaces',
                    $this->actions->count(),
                ));
            },
            function (\Throwable $e): void {
                $this->logger->error('[bot] twitch failed to start: ' . $e->getMessage());
                $this->logger->warning('[bot] running Discord-only; relay and Twitch commands are unavailable');

                (new DiscordAdapter($this, $this->actions))->register();
            },
        );
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

        $ownerId = $this->config->discordOwnerId;

        if ($ownerId === null || $ownerId === '') {
            return;
        }

        $this->users->fetch($ownerId)->then(
            fn ($user) => $user->sendMessage(sprintf(
                "⚠️ **Twitch needs re-authorizing.**\nOpen <%s> and enter `%s` — it expires in %d minutes.",
                $uri,
                $code,
                (int) round($expires / 60),
            )),
        )->then(null, fn (\Throwable $e) => $this->logger->error(
            '[bot] could not DM the owner about re-authorization: ' . $e->getMessage(),
        ));
    }
}
