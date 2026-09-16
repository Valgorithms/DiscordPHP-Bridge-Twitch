# DiscordPHP-TwitchBot

A Discord bot and a Twitch bot that are the same bot.

Built on [DiscordPHP](https://github.com/discord-php/DiscordPHP)'s
`MessageCommandClient` and [TwitchPHP](https://github.com/Valgorithms/TwitchPHP)'s
`CommandClient`, sharing one ReactPHP event loop. Its headline feature is a
configurable two-way chat relay; behind that, the whole Twitch Helix API is
driven from either chat.

```
#general  ──────────►  twitch.tv/twitchdev
          ◄──────────

!title Back in ten      ← works in either one, and means the same thing
```

## The idea

A command is declared **once**, as an `Action`, and registered into both chat
clients:

```php
new Action(
    'title',
    $this->title(...),
    'Show the stream title, or set it',
    '[new title]',
    access: Access::Everyone,
);
```

Handlers never see a `Message` or a `ChatMessage`. They get a `Context` — who
asked, from where, what they may do, and which Twitch channel this acts on —
and return a string. Two adapters do the rest, and they are the only code in
the project that knows either platform exists.

That `Context` is what makes one definition serviceable from two places. On
Twitch, the channel a command was typed in *is* the channel it acts on, and its
id arrives in the `room-id` tag. On Discord there is no such thing, so the
target is the Twitch channel bridged to that Discord channel. `!title` does the
same thing in both places without either handler knowing how the other one
resolved it.

## Setup

```bash
composer install
cp env.example .env    # then fill it in
php bot.php
```

Three things are easy to miss:

- **Enable the Message Content intent** on the Discord application page.
  Without it every message arrives empty — no relay, and no command is ever
  recognised.
- **Grant the bot Manage Webhooks** in a relayed channel. Without it the relay
  still works, but Twitch chat arrives as plain `**name:** message` bot messages
  instead of per-chatter names and avatars.
- **`TWITCH_CLIENT_SECRET` is optional, but you want it.** See below.

### Running without a client secret

The bot starts and relays fine without one: Helix calls send only the bearer
token and `Client-Id`, and IRC needs neither. What breaks is *refreshing* —
`OAuth::form()` rejects every grant except device-code when no secret is set.

So the token cannot be renewed, and roughly four hours later the bot asks for a
device code instead: it logs a prominent warning and, if `DISCORD_OWNER_ID` is
set, DMs you a `twitch.tv/activate` code. Approve it and it carries on.

That is fine for testing and no good for unattended operation. Note that Twitch
never shows an existing secret twice — generating a new one invalidates the old,
which breaks anything else sharing that client id, so a second Twitch
application is often the cleaner move.

## The relay

```
relay link twitchdev              bridge this channel to twitch.tv/twitchdev
relay link twitchdev #general     ...or to a named one
relay unlink [#channel]           stop
relay list                        what this server has configured
relay reset                       clear it all
bridge                            where am I relaying? (works in Twitch chat too)
```

`relay` is restricted to the server owner or anyone with **Administrator** /
**Manage Server**, and that gate is the security model for the whole project:
whoever can run it decides which Discord channel gets copied into a public
Twitch chat. Point it at a private channel and that channel is on stream.

`link` accepts a bare name, `#name`, or a full `twitch.tv/...` URL, and checks
the channel exists before wiring it up — a typo otherwise produces a bridge that
silently never works, because the bot joins a channel that isn't there and never
hears anything.

Several Discord servers may follow the same streamer; they share one IRC
membership and each gets a copy.

### Loop prevention

A bridge that repeats itself is an infinite loop that gets the account banned
from both networks. Each direction drops its own output as early as it can:

- **Discord → Twitch** ignores any message carrying a `webhook_id`, and any
  message from a bot. Relayed Twitch chat arrives *through* a webhook, so that
  first rule is the one doing the work. Other bots are dropped deliberately:
  two bridges in one channel would otherwise ping-pong forever.
- **Twitch → Discord** ignores anything sent by the bot's own nick, because IRC
  echoes our own `PRIVMSG`s back to us.

Commands are dropped in both directions too — `!title something` is an
instruction to the bot, not a remark, and relaying it would put every command
into the stream's chat. Only a *registered* command counts, so ordinary chat
full of `!` still relays.

## Commands

Everything below works from Discord **and** Twitch chat unless marked
otherwise. `help` lists what you personally can run; `help <command>` explains
one.

| | |
| --- | --- |
| `title [text]` | Show the stream title, or set it |
| `game [name]` | Show the category, or set it |
| `tags [a, b, c]` | Show the tags, or set them |
| `channel` | Everything the channel is currently set to |
| `uptime` · `viewers` · `stream` | The live stream |
| `followers` | Follower count |
| `search <name>` | Find a category by name |
| `clip` · `marker [note]` | Capture something (moderator) |
| `ban` · `unban` · `timeout` | Moderation (moderator) |
| `clear` · `announce` · `shoutout` | Chat (moderator) |
| `slow` · `subonly` · `emoteonly` · `followersonly` | Chat modes (moderator) |
| `vip` · `unvip` · `mod` · `unmod` | Roles (broadcaster) |
| `raid` · `unraid` · `commercial` | Broadcast (broadcaster) |
| `relay ...` | Configure the relay (Discord, admin) |
| `api ...` | Anything else (owner) |

Permissions map onto one ladder — everyone, moderator, broadcaster, owner —
because two systems' worth of permissions would have to be explained twice.
Twitch reads it off chat badges; Discord treats the guild owner and
Administrators as the broadcaster, and Manage Messages as moderator.

### `api` — the rest of Helix

The commands above cover what a chat asks for day to day. `api` covers
everything else: **every method of every TwitchPHP repository**, reachable by
name, with arguments matched to parameters and the response rendered back into
chat.

```
api list                          29 repositories
api list moderation               every call on one
api help moderation.warn          its signature
api moderation.warn broadcaster_id=1 moderator_id=2 user_id=3 reason=spam
api channels.modify broadcaster_id=29034572 fields={"title":"Back in ten"}
```

That is 140 endpoint methods, and the list is not maintained here — the
repositories, their methods, parameter names, types and defaults all come from
reflection over TwitchPHP itself. A repository added upstream is reachable with
no change to this project, and `api help` cannot drift out of date because there
is no second catalogue for it to drift from.

Arguments are matched **by name only**. Positional arguments are deliberately
not accepted: a mis-ordered `ban(broadcasterId, moderatorId, userId)` would ban
the wrong person, silently and irreversibly.

`api` is gated to `DISCORD_OWNER_ID` / `TWITCH_OWNER_LOGIN` — not to the
broadcaster, and not to moderators. It reaches endpoints that ban users, end
streams and rewrite a channel, using the bot's own token. With neither set it
is unreachable from both chats, which is the default.

## Safety

**IRC injection.** `Irc::say()` interpolates into `PRIVMSG #chan :<text>\r\n`.
A message containing CR or LF would end that line, and the remainder would be
parsed as a fresh IRC command — letting anyone who can make the bot speak issue
arbitrary IRC as the bot account. Every outbound line goes through
`TwitchAdapter::safeLine()`, which is [tested directly](tests/SafetyTest.php).

**Twitch commands.** Twitch reads a leading `/` or `.` as a command. Relayed
lines carry an `author:` prefix so they can never begin with one, and command
replies are checked separately — an API error quoting a path would otherwise be
executed rather than said.

**Credentials.** `streams.key` returns a live stream key; anyone holding it can
broadcast to the channel. Such calls are refused on Twitch outright and answered
by DM on Discord. Separately, *every* rendered response is passed through a
field-level redaction pass that masks anything named like a credential. The
first list is a judgement about today's API and will age; the second is what
catches the endpoint nobody thought about.

**Mentions.** Twitch chat is untrusted input, so everything delivered into
Discord is sent with `allowed_mentions: {parse: []}`. A viewer typing
`@everyone` still reads as having typed it, but pings nobody.

**Rate limits.** Twitch mutes the *account* for 30 minutes if you exceed 20
messages per 30 seconds — not merely the message. Relayed chat and command
replies both go through one per-channel token bucket sized at 18, because two
senders that each stay under the limit will still breach it together.

**Editing a channel** uses the bot's own token, so the bot account must be the
broadcaster or a channel editor. When it isn't, Twitch answers 401 — which is
translated, because the raw version sends people looking at their token when the
token is fine.

## Layout

```
bot.php                          entrypoint: build the catalogue, run
src/TwitchBot/
  Bot.php                        both clients on one loop
  Config.php                     settings, from the environment only
  Store.php  Links.php           relay persistence, and the routing table
  Command/
    Action.php  ActionRegistry.php    what a command is; the catalogue
    Context.php  Arguments.php        who asked; what they typed
    Access.php  Surface.php           one permission ladder; two chats
    DiscordAdapter.php  TwitchAdapter.php
  Actions/                       the commands themselves, by feature
  Api/
    RepositoryDispatcher.php     reflective access to every repository
    Sensitive.php                what must never reach a chat
  Relay/
    ChatRelay.php                both directions, and loop prevention
    TwitchGateway.php            IRC joins/parts, paced sending
    WebhookDelivery.php          into Discord, with a fallback
  Support/                       text safety, formatting, rate limiting
```

The logic worth testing is deliberately pure — routing, parsing, sanitisation,
redaction, pacing and the permission ladder are all verifiable without a socket:

```bash
composer test
```

## Licence

MIT.
