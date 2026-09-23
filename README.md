# DiscordPHP-Bridge-Twitch

The Twitch connector for [DiscordPHP-Bridge](https://github.com/discord-php/DiscordPHP-Bridge):
a two-way chat bridge, the whole Helix API, and one command catalogue shared
with every other network the bot is on.

```
#general  ──────────►  twitch.tv/twitchdev
          ◄──────────
```

Built on [TwitchPHP](https://github.com/Valgorithms/TwitchPHP), sharing the
bot's ReactPHP event loop.

## Installing it

```php
use Bridge\Twitch\{TwitchConfig, TwitchConnector};

if (TwitchConfig::isConfigured($environment)) {
    $bot->addConnector(new TwitchConnector(TwitchConfig::fromEnvironment($environment)));
}
```

That is the whole integration. The connector arrives with `link`, `here`,
`unlink`, `list`, `status` and `reset` already written — the core defines those
once for every connector — so what is in this package is only what is actually
about Twitch.

`.env` needs a Twitch application plus a user token for the account the bridge
speaks as (`chat:read` and `chat:edit`). That account is the host's own:
`TWITCH_NICK` is its login, not the application's name, which is not an account
and cannot chat. What the host types in Twitch chat is relayed like anyone
else's; only lines the bridge itself just sent are held back.

The connector refreshes that token and writes the new pair back to `.env`
itself: Twitch invalidates the old refresh token on every rotation, so anything
that does not persist it locks itself out on the next restart. For the same
reason, don't share one token pair between two projects — whichever refreshes
first breaks the other's copy.

### Running without a client secret

The bridge starts and relays fine without one: Helix calls send only the bearer
token and `Client-Id`, and IRC needs neither. What breaks is *refreshing* —
`OAuth::form()` rejects every grant except device-code when no secret is set.

So the token cannot be renewed, and roughly four hours later the connector asks
for a device code instead: it logs a prominent warning and, if the bot has an
owner configured, DMs them a `twitch.tv/activate` code. Approve it and it
carries on.

That is fine for testing and no good for unattended operation. Note that Twitch
never shows an existing secret twice — generating a new one invalidates the old,
which breaks anything else sharing that client id, so a second Twitch
application is often the cleaner move.

## Commands

Everything below works four ways — as a Discord slash command, as a Discord
prefix command, in Twitch chat, and in the chat of any *other* network the bot
is bridged to. `!twitch` on its own lists what you can run.

| | |
| --- | --- |
| `/twitch link` · `here` · `unlink` · `list` · `status` · `reset` | the bridge (admin) |
| `/twitch channel title` · `game` · `tags` · `info` | what the channel is set to |
| `/twitch stream uptime` · `viewers` · `live` · `followers` · `clip` · `marker` | the live stream |
| `/twitch moderation ban` · `unban` · `timeout` · `clear` · `announce` · `shoutout` · `slow` · `subonly` · `emoteonly` · `followersonly` · `vip` · `unvip` · `mod` · `unmod` | moderation |
| `/twitch cast raid` · `unraid` · `commercial` | broadcast (broadcaster) |
| `/twitch search <name>` | find a category |
| `/twitch api …` | anything else (owner) |

A chat can drop the group but never the qualifier — `!twitch title` (the long
`!twitch channel title` works too), never a bare `!title`. That is deliberate: a name is
only free because no connector has claimed it yet, and since every connector's
commands are offered in every chat, an unqualified `!title` is one installed
package away from meaning two things.

Permissions map onto the core's one ladder — everyone, moderator, administrator,
operator — because two systems' worth of permissions would have to be explained
twice. Twitch reads it off chat badges; Discord treats the guild owner and
Administrators as the administrator rung, and Manage Messages as moderator.

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

`api` is gated to the bot's operator — not to the broadcaster, and not to
moderators. It reaches endpoints that ban users, end streams and rewrite a
channel, using the bot's own token. With no operator configured it is
unreachable, which is the default.

## Safety

**IRC injection.** `Irc::say()` interpolates into `PRIVMSG #chan :<text>\r\n`.
A message containing CR or LF would end that line, and the remainder would be
parsed as a fresh IRC command — letting anyone who can make the bot speak issue
arbitrary IRC as the bot account. Every outbound line goes through
`TwitchText::safeLine()`, which is [tested directly](tests/SafetyTest.php).

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

**Rate limits.** Twitch mutes the *account* for 30 minutes if you exceed 20
messages per 30 seconds — not merely the message — and the count is across every
channel the account speaks in. Relayed chat and command replies all go through
one account-wide token bucket sized at 18, because two senders that each stay
under the limit will still breach it together, and so will two channels. At most
100 messages wait; past that the oldest are dropped, since a busy Discord channel
outpaces what Twitch will take. The Discord side is paced by the core, which has
its own reasons.

**Editing a channel** uses the bot's own token, so the bot account must be the
broadcaster or a channel editor. When it isn't, Twitch answers 401 — which is
translated, because the raw version sends people looking at their token when the
token is fine.

## Coming from DiscordPHP-TwitchBot

This repository *is* that project, with everything that was not about Twitch
moved into the core. The commands were renamed to make room for other networks:

| Before | Now |
| --- | --- |
| `/relay link twitch:x channel:#y` | `/twitch link target:x channel:#y` |
| `/relay unlink` · `list` · `reset` | `/twitch unlink` · `list` · `reset` |
| `/bridge` | `/twitch status` |
| `/title` · `/game` · `/tags` · `/channel` | `/twitch channel title` · `game` · `tags` · `info` |
| `/uptime` · `/viewers` · `/stream` | `/twitch stream uptime` · `viewers` · `live` |
| `/ban` · `/timeout` · `/vip` · … | `/twitch moderation ban` · `timeout` · `vip` · … |
| `/raid` · `/unraid` · `/commercial` | `/twitch cast raid` · `unraid` · `commercial` |
| `/api` | `/twitch api` |
| `/help` · `/about` | `/bridge help` · `/bridge about` |

The old names are unregistered from Discord automatically on the first boot
where every connector starts.

**The stored state is compatible.** A `storage/bridges.json` written by the old
bot is migrated on load into the connector-keyed shape, so every configured
bridge survives with nothing to re-run.

## Layout

```
src/Bridge/Twitch/
  TwitchConnector.php      the client, the scopes, device-code recovery
  TwitchConfig.php         settings, from the environment only
  TwitchGateway.php        IRC joins/parts, paced sending
  TwitchAdapter.php        the catalogue, in Twitch chat
  TwitchText.php           what IRC and Twitch demand of a line
  Api/
    RepositoryDispatcher.php   reflective access to every repository
    Sensitive.php              what must never reach a chat
  Actions/                 the commands themselves, by feature
```

```bash
composer test
```

## Licence

MIT.
