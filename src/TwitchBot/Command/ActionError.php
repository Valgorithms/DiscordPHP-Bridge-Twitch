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

namespace TwitchBot\Command;

/**
 * An action failing for a reason the person who typed it should read.
 *
 * Distinct from every other exception on purpose: the adapters relay an
 * `ActionError`'s message straight into chat, and deliberately do *not* relay
 * anything else. A Helix client error or a null dereference gets a flat
 * apology in chat and the detail in the log, because exception text is written
 * for whoever is reading a stack trace, and can carry internals — a URL with a
 * token in the query string, a file path — that has no business in a public
 * Twitch channel.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class ActionError extends \RuntimeException
{
}
