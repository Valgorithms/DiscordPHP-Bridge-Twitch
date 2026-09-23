<?php

declare(strict_types=1);

/*
 * This file is a part of the DiscordPHP-Bridge-Twitch project.
 *
 * Copyright (c) 2026-present Valithor Obsidion <valithor@valgorithms.com>
 *
 * This file is subject to the MIT license that is bundled
 * with this source code in the LICENSE.md file.
 */

namespace Bridge\Twitch;

use Bridge\Bot;
use Bridge\Capability\Avatars;
use Bridge\Capability\ProvidesActions;
use Bridge\Command\Surface;
use Bridge\Connector;
use Bridge\Links;
use Bridge\Message\Incoming;
use Bridge\Message\Outgoing;
use Bridge\Room;
use Bridge\Twitch\Actions\ApiActions;
use Bridge\Twitch\Actions\ChannelActions;
use Bridge\Twitch\Actions\ModerationActions;
use Bridge\Twitch\Actions\StreamActions;
use Bridge\Twitch\Api\RepositoryDispatcher;
use React\Promise\PromiseInterface;
use Twitch\Auth\DeviceCodeReauthorizer;
use Twitch\Auth\EnvFileTokenStore;
use Twitch\Http\OAuth;
use Twitch\Parts\ChatMessage;
use Twitch\Twitch;

use function React\Promise\resolve;

/**
 * Twitch, as far as the bridge is concerned.
 *
 * Owns the TwitchPHP client, the IRC membership, the Helix dispatcher and the
 * device-code recovery, and hands the core {@see Incoming} messages and
 * {@see Room} descriptions like any other connector. Nothing above this knows
 * what IRC is.
 *
 * `Twitch::run()` would call `Loop::run()` itself, so the client is started
 * through `bootstrap()` and `Discord::run()` is left to drive the loop.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class TwitchConnector implements Connector, ProvidesActions, Avatars
{
    /** The name this connector is addressed by, in the store and in chat. */
    public const NAME = 'twitch';

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
     * {@see \Twitch\Http\Exceptions\MissingScopeException} naming the scope it
     * wanted, which is a better outcome than holding every permission on the
     * chance somebody types one.
     *
     * Verified against the Twitch OpenAPI description rather than the docs
     * pages. {@link https://github.com/DmitryScaletta/twitch-api-swagger}
     *
     * @var list<string>
     */
    public const SCOPES = [
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

    private Bot $bot;

    private Twitch $twitch;

    private RepositoryDispatcher $dispatcher;

    private ?TwitchGateway $gateway = null;

    /** How many user rows to keep. Every new chatter is one, for their avatar. */
    private const USER_CACHE = 1000;

    private ?TwitchAdapter $adapter = null;

    /** @var list<callable(Incoming): void> */
    private array $handlers = [];

    /** @var array<string, array{id: string, login: string, display_name: string, description: string, avatar: string}|null> login => cached user row, negatives included. */
    private array $users = [];

    /** @var array<string, PromiseInterface<array{id: string, login: string, display_name: string, description: string, avatar: string}|null>> Lookups in flight, so a burst from one chatter is one request. */
    private array $pending = [];

    public function __construct(private readonly TwitchConfig $config)
    {
    }

    // ── Identity ───────────────────────────────────────────────────────

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'Twitch';
    }

    /**
     * Twitch chat takes 500 characters of flat text on a single line.
     *
     * `lines: false` is not a stylistic note — a newline in a `PRIVMSG` is an
     * IRC injection, so the core must never assume one can be sent.
     */
    public function surface(): Surface
    {
        return new Surface(self::NAME, 'Twitch', TwitchText::LIMIT, markdown: false, lines: false, prefix: $this->config->prefix);
    }

    public function getTwitch(): Twitch
    {
        return $this->twitch;
    }

    public function getDispatcher(): RepositoryDispatcher
    {
        return $this->dispatcher;
    }

    public function getConfig(): TwitchConfig
    {
        return $this->config;
    }

    // ── Lifecycle ──────────────────────────────────────────────────────

    public function boot(Bot $bot): void
    {
        $this->bot = $bot;

        $this->twitch = new Twitch([
            'client_id' => $this->config->clientId,
            'client_secret' => $this->config->clientSecret,
            'token' => $this->config->token,
            'refresh_token' => $this->config->refreshToken,
            'nick' => $this->config->nick,
            'channels' => $bot->getStore()->links(self::NAME)->targets(),
            'command_prefix' => $this->config->prefix,
            'loop' => $bot->getLoop(),
            'logger' => $bot->getLogger(),
            // Twitch rotates the refresh token on every refresh; without this
            // the bot locks itself out of its own account on restart.
            'token_store' => new EnvFileTokenStore($this->config->envPath),
            // Last resort when refreshing cannot recover the grant — which is
            // always, when no client secret is configured.
            'reauthorize' => $this->reauthorizer(),
        ]);

        $this->dispatcher = new RepositoryDispatcher($this->twitch);
    }

    /**
     * Connects, and starts answering commands in chat.
     *
     * `bootstrap()` resolves once the token is usable and IRC is up; anything
     * that needs a live client happens inside it. A rejection is handed back
     * to the core, which reports this connector as down without taking the
     * others with it.
     */
    public function start(): PromiseInterface
    {
        return $this->twitch->bootstrap()->then(function (): bool {
            $this->gateway = new TwitchGateway(
                $this->twitch,
                $this->bot->getLoop(),
                $this->bot->getLogger(),
                $this->config->nick,
            );

            $this->adapter = new TwitchAdapter($this, $this->bot);

            $this->gateway->listen();
            $this->gateway->onChat(fn (ChatMessage $message) => $this->dispatch($message));

            $this->bot->getLogger()->info(sprintf(
                '[twitch] ready as %s; %d bridge(s) configured, commands start with %s',
                (string) $this->twitch->getLogin(),
                $this->bot->getStore()->links(self::NAME)->count(),
                $this->config->prefix,
            ));

            $this->warnIfCannotRefresh();
            $this->warnIfTokenIsSomeoneElse();

            return true;
        });
    }

    public function stop(): void
    {
        $this->twitch->close();
    }

    // ── Rooms ──────────────────────────────────────────────────────────

    public function sync(Links $links): array
    {
        return $this->gateway?->sync($links) ?? ['join' => [], 'part' => []];
    }

    public function joined(): array
    {
        return $this->gateway?->joined() ?? [];
    }

    public function queued(): int
    {
        return $this->gateway?->queued() ?? 0;
    }

    public function normalise(string $input): ?string
    {
        return TwitchText::normalizeLogin($input);
    }

    /**
     * Looks a channel up by login.
     *
     * The room is keyed by login — what IRC joins and what the store has held
     * since the single-platform bot — and carries the Helix user id as its
     * {@see Room::apiId()}, which is what every Helix call wants as
     * `broadcaster_id`.
     *
     * @return PromiseInterface<?Room>
     */
    public function resolve(string $target): PromiseInterface
    {
        return $this->lookupUser($target)->then(static fn (?array $user): ?Room => $user === null ? null : new Room(
            id: $user['login'],
            label: $user['display_name'] !== '' ? $user['display_name'] : $user['login'],
            url: 'https://twitch.tv/' . $user['login'],
            kind: 'channel',
            description: $user['description'] === '' ? null : $user['description'],
            avatarUrl: $user['avatar'] === '' ? null : $user['avatar'],
            platformId: $user['id'],
        ));
    }

    /**
     * One Helix user row by login, cached — including a user that does not
     * exist, so a typo is not re-queried on every relayed message.
     *
     * Resolves `null` only when Twitch says there is no such user. A lookup
     * that *failed* rejects: it is not proof of absence, and a moderation
     * command must not treat it as one.
     *
     * @return PromiseInterface<array{id: string, login: string, display_name: string, description: string, avatar: string}|null>
     */
    public function lookupUser(string $login): PromiseInterface
    {
        $login = strtolower($login);

        if (array_key_exists($login, $this->users)) {
            return resolve($this->users[$login]);
        }

        if (isset($this->pending[$login])) {
            return $this->pending[$login];
        }

        $lookup = $this->twitch->users->fetchByLogin($login)->then(function ($user) use ($login): ?array {
            $row = $user === null ? null : [
                'id' => (string) $user->id,
                'login' => strtolower((string) $user->login),
                'display_name' => (string) $user->display_name,
                'description' => (string) ($user->description ?? ''),
                'avatar' => (string) ($user->profile_image_url ?? ''),
            ];

            if (count($this->users) >= self::USER_CACHE) {
                // Oldest first: arrays keep insertion order.
                unset($this->users[array_key_first($this->users)]);
            }

            return $this->users[$login] = $row;
        });

        // Stored before the cleanup is attached, so a lookup that settles at
        // once is not left behind — a rejected one would fail every later
        // lookup of the same name.
        $this->pending[$login] = $lookup;
        $forget = function () use ($login, $lookup): void {
            if (($this->pending[$login] ?? null) === $lookup) {
                unset($this->pending[$login]);
            }
        };
        $lookup->then($forget, $forget);

        return $lookup;
    }

    /**
     * A chatter's profile picture, for the Discord copy of what they said.
     *
     * Helix's `profile_image_url` is a public CDN link with nothing secret in
     * it. Never rejects: a missing avatar is not worth losing the message over.
     */
    public function avatarFor(Incoming $message): PromiseInterface
    {
        $login = $message->handle ?? '';

        if ($login === '') {
            return resolve(null);
        }

        return $this->lookupUser($login)->then(
            static fn (?array $user): ?string => $user === null || $user['avatar'] === '' ? null : $user['avatar'],
            static fn (): ?string => null,
        );
    }

    // ── Messages ───────────────────────────────────────────────────────

    public function send(string $target, string $text, array $options = []): PromiseInterface
    {
        $text = TwitchText::safeLine($text);

        if ($text === '' || $this->gateway === null) {
            return resolve(null);
        }

        $this->gateway->send($target, $text, isset($options['reply_to']) ? (string) $options['reply_to'] : null);

        return resolve(null);
    }

    public function relay(string $target, Outgoing $message): PromiseInterface
    {
        $text = TwitchText::compose($message);

        return $text === null ? resolve(null) : $this->send($target, $text);
    }

    public function onIncoming(callable $handler): void
    {
        $this->handlers[] = $handler;
    }

    /** @return list<\Bridge\Command\Action> */
    public function actions(): array
    {
        return [
            ...(new ChannelActions())->actions(),
            ...(new StreamActions())->actions(),
            ...(new ModerationActions())->actions(),
            ...(new ApiActions())->actions(),
        ];
    }

    // ── Internals ──────────────────────────────────────────────────────

    /**
     * Turns a TwitchPHP chat message into the core's own shape.
     *
     * The gateway has already dropped the bridge's own lines, so everything
     * that reaches here was typed by a person — the host included, since the
     * bot speaks as the host's account. Their messages are relayed and their
     * commands answered like anyone else's, at the rank Twitch gives them.
     */
    private function dispatch(ChatMessage $message): void
    {
        // Commands are answered here and dropped by the relay, which asks the
        // same dispatcher whether a line is one.
        $this->adapter?->handle($message);

        $incoming = new Incoming(
            target: strtolower((string) $message->channel),
            author: (string) ($message->display_name ?: $message->user),
            authorId: (string) ($message->user_id ?? '') ?: null,
            text: (string) $message->content,
            id: (string) ($message->id ?? '') ?: null,
            handle: strtolower((string) $message->user),
        );

        foreach ($this->handlers as $handler) {
            $handler($incoming);
        }
    }

    private function warnIfCannotRefresh(): void
    {
        if ($this->config->canRefreshToken()) {
            return;
        }

        // Say this at startup, not in four hours when the token dies and
        // somebody has to work out why chat went quiet.
        $this->bot->getLogger()->warning(
            '[twitch] no TWITCH_CLIENT_SECRET — the access token cannot be refreshed. '
            . 'When it expires (~4h) the bot will ask for a device code instead. '
            . 'Set a client secret for unattended operation.',
        );
    }

    /**
     * Says so when the token belongs to a different account than TWITCH_NICK.
     *
     * Twitch sends chat as whoever the token belongs to, whatever nick the
     * connection claims — so a token approved while logged in as the streamer
     * makes the relay speak as the streamer. Nothing fails, which is what makes
     * it worth saying: the first sign is a relayed message under the wrong name.
     */
    private function warnIfTokenIsSomeoneElse(): void
    {
        $login = strtolower((string) $this->twitch->getLogin());
        $nick = strtolower($this->config->nick);

        if ($login === '' || $login === $nick) {
            return;
        }

        $this->bot->getLogger()->warning(sprintf(
            '[twitch] the token belongs to %1$s, but TWITCH_NICK is %2$s — chat will be sent as %1$s. '
            . 'TWITCH_NICK must be the login of a Twitch account, not the name of the application in the developer console. '
            . 'If the bot should speak as %2$s, re-authorize logged in to Twitch as %2$s; if %1$s is right, set TWITCH_NICK=%1$s.',
            $login,
            $nick,
        ));
    }

    /**
     * The device-code reauthorizer, and the prompt that reaches a human.
     *
     * Device-code is the one grant `OAuth::form()` allows without a client
     * secret, so this is the only route back to a working token when none is
     * configured.
     *
     * The prompt goes two places: the log, always, and a DM to the bot's owner
     * when one is set. A bot that needs re-authorization and only whispers it
     * into a logfile stays down until somebody looks.
     */
    private function reauthorizer(): DeviceCodeReauthorizer
    {
        $oauth = new OAuth(
            $this->config->clientId,
            $this->config->clientSecret,
            $this->bot->getLoop(),
        );

        return new DeviceCodeReauthorizer(
            $oauth,
            self::SCOPES,
            fn (array $device) => $this->promptForDeviceCode($device),
            $this->bot->getLoop(),
        );
    }

    /** @param array<string, mixed> $device */
    private function promptForDeviceCode(array $device): void
    {
        $uri = (string) ($device['verification_uri'] ?? 'https://www.twitch.tv/activate');
        $code = (string) ($device['user_code'] ?? '?');
        $expires = (int) ($device['expires_in'] ?? 1800);

        $this->bot->getLogger()->warning(sprintf(
            "[twitch] RE-AUTHORIZATION NEEDED — the Twitch half is degraded until this is approved.\n"
            . "        Open %s and enter %s\n"
            . '        (expires in %ds)',
            $uri,
            $code,
            $expires,
        ));

        $this->bot->notifyOwner(sprintf(
            "⚠️ **Twitch needs re-authorizing.**\nOpen <%s> and enter `%s` — it expires in %d minutes.",
            $uri,
            $code,
            (int) round($expires / 60),
        ));
    }
}
