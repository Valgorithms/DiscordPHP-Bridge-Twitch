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

!title Back in ten      ← Discord chat, Twitch chat, or /title — same command
```

## The idea

A command is declared **once**, as an `Action`, and registered into both chat
clients — and, where it declares typed parameters, as a Discord slash command
as well:

```php
new Action(
    'title',
    $this->title(...),
    'Show the stream title, or set it',
    '[new title]',
    access: Access::Everyone,
    slash: new Slash([new SlashOption('text', 'The new title.')]),
);
```

Handlers never see a `Message`, a `ChatMessage` or an `Interaction`. They get a
`Context` — who asked, from where, what they may do, and which Twitch channel
this acts on — and return a string. Three adapters do the rest, and they are the
only code in the project that knows any platform exists.

The slash adapter renders Discord's typed options back into the text the prefix
form would have produced — a channel picker becomes `<#id>` — so `/relay link
twitch:x` and `!relay link x` run the same handler down to the argument indices.

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
  Without it the relay cannot read messages to relay, and prefix commands are
  never recognised. Every command is also a slash command, so those keep
  working — but the relay does not.
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
/relay link   twitch:twitchdev [channel:#general]    bridge a channel
/relay unlink [channel:#general]                     stop
/relay list                                          what this server has
/relay reset                                         clear it all
/bridge                                              where am I relaying?
```

Or by prefix, identically:

```
!relay link twitchdev [#general]
!relay unlink [#channel]
!relay list
!relay reset
!bridge                           works in Twitch chat too
```

`/relay` replies are ephemeral — configuration is nobody else's business, and it
keeps the channel clean.

`relay` is restricted to the server owner or anyone with **Administrator** /
**Manage Server**, and that gate is the security model for the whole project:
whoever can run it decides which Discord channel gets copied into a public
Twitch chat. Point it at a private channel and that channel is on stream.

`link` accepts a bare name, `#name`, or a full `twitch.tv/...` URL, and checks
the channel exists before wiring it up — a typo otherwise produces a bridge that
silently never works, because the bot joins a channel that isn't there and never
hears anything. It also checks its own permissions in the target channel and
says so up front, rather than letting the first relayed message vanish: a relay
that is configured correctly but cannot post looks exactly like one that is
misconfigured, and the only evidence is an absence.

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

### Attachments

A Discord attachment relays as its CDN link, so chat can open the picture rather
than be told one exists. Links are budgeted before the message text and appended
after it is shortened — a URL with its tail cut off is not a URL, whereas a
shortened sentence still reads. Whole links only: any that will not fit are
counted as `(+2 more)` rather than truncated into something that looks clickable
and goes nowhere.

**Those links expire.** Discord signs attachment URLs and they stop working
roughly a day after they are issued, so a relayed link is good for people
reading along live and dead by the time anyone reads the logs. There is no way
around it from this side — the unsigned form of these URLs no longer exists.

## Surviving a restart

Bridges set up with `relay link` live in `storage/bridges.json` and are
reloaded on every start — nothing has to be set up again, and the Twitch
connection rejoins exactly the channels that were bridged. That file is the
only record of them, so it is treated as one:

- **Writes are atomic and flushed to disk.** A crash mid-write, or a machine
  losing power, cannot leave a half-written file where the configuration was.
- **The last good copy is kept** beside it as `bridges.json.bak`, written after
  each successful save.
- **A damaged file is never silently replaced.** If the JSON doesn't parse the
  backup is used; if that fails too, the file is preserved as
  `bridges.json.corrupt-<timestamp>` and the bot starts empty rather than
  overwriting it on the next `relay link`.
- **Entries of the wrong shape are dropped, not loaded**, so a hand-edited file
  can't take the bot down — and the good entries in it still survive the next
  write.

Ten seconds after startup the bot checks what it restored: can it still see
each Discord channel, does each Twitch channel still exist, and — the one a
restart is specifically meant to re-establish — is the IRC connection actually
in it? A JOIN that silently failed leaves a bridge that works in one direction
only. Findings go to the log and, if `DISCORD_OWNER_ID` is set, to a DM, and
the headline is repeated at the foot of `/relay list` — a bridge whose channel
or streamer went away while the bot was down reads exactly like a working one
from a listing alone. Nothing is pruned automatically: a guild can be briefly unavailable during an
outage, and deleting someone's configuration over a bad ten seconds is worse
than telling them about it.

### Disk I/O and the event loop

A blocking write stops the loop: while it runs, no heartbeat is sent and
nothing is relayed. Saves therefore go through
[react/filesystem](https://github.com/reactphp/filesystem) — which performs
them off the loop **only** with `ext-uv` (Linux, macOS and Windows) or
`ext-eio` (POSIX). With neither, that library's own fallback is
`file_put_contents()` wrapped in an already-resolved promise, so the bot does
the write itself instead and, since it is blocking anyway, blocks *properly*:
`fflush()` and `fsync()`, which `putContents()` cannot express. Measured on a
Windows host: 3.5 ms blocking, 0.07 ms with an async backend. The bot logs
which one it picked at startup.

Either way the caller never waits — `relay link` answers from memory, the write
is queued, rapid changes coalesce into one write, and Ctrl-C flushes anything
outstanding before the loop stops.
## Commands

Everything below works three ways — as a Discord slash command, as a Discord
prefix command, and in Twitch chat — unless marked otherwise. `help` lists what
you personally can run; `help <command>` explains one.

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

All 33 are registered as slash commands, so a server that has not granted the
Message Content intent still gets every command — it only loses the relay. A
test asserts that stays true.

Registration only writes what changed. Publishing all 33 on every boot is 33
rate-limited writes to say nothing, and publishing only the ones Discord has
never seen would freeze each command in the shape it had the first time — add a
sub-command and it is routed in code but never offered. So each definition is
compared against what Discord already has, leniently enough that the fields it
adds on the way back (`id`, `version`, defaults, key order) don't read as a
change, and only a real difference is sent. The startup line says how many.

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

## Coming from DiscordPHP-TwitchRelay

This project supersedes it. Everything the relay did, this does:

| Relay | Here |
| --- | --- |
| `/config set channel:#x twitch:y` | `/relay link twitch:y channel:#x` |
| `/config unset channel:#x` | `/relay unlink channel:#x` |
| `/config view` | `/relay list` |
| `/config reset` | `/relay reset` |

Same permission gate (owner / Administrator / Manage Server), same ephemeral
replies, same up-front warning when the bot cannot post in the target channel,
same loop prevention, same IRC sanitisation, same rate limiting.

**The stored state is compatible.** `Store` and `Links` are the same code, and
both write the same `{"links": {guild: {channel: login}}}` shape — so copying
the file across preserves every configured bridge. Only the path changed
(`var/relay.json` → `storage/bridges.json`):

```bash
mkdir -p storage && cp ../DiscordPHP-TwitchRelay/var/relay.json storage/bridges.json
```

Two things do change. The command is `/relay`, not `/config` — deliberately, as
`/config` is a name several bots want and Tutelar already registers it globally.
And `TWITCH_OWNER_LOGIN` is new; without it or `DISCORD_OWNER_ID`, `api` is
unreachable.

If you run both at once, give them separate Discord applications and separate
Twitch token pairs — two bots refreshing one pair will lock each other out.

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
  Support/                       text safety, formatting, rate limiting,
                                 async disk I/O, the startup bridge check
```

The logic worth testing is deliberately pure — routing, parsing, sanitisation,
redaction, pacing and the permission ladder are all verifiable without a socket:

```bash
composer test
```

## Licence

MIT.
