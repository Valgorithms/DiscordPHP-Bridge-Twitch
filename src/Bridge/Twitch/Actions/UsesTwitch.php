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

namespace Bridge\Twitch\Actions;

use Bridge\Command\ActionError;
use Bridge\Command\Context;
use Bridge\Twitch\TwitchConnector;

/**
 * Reaches the Twitch client from inside an action.
 *
 * An action is handed a {@see Context}, which knows the bot but deliberately
 * not the network — that is what lets the same catalogue serve every chat. A
 * Twitch action does need the Twitch client, so it asks the bot for the
 * connector by name.
 *
 * It can genuinely be absent: the connector is installed only when the
 * environment carries Twitch credentials, and it can fail to start while the
 * rest of the bot runs on. Saying so is better than a `TypeError` two frames
 * down, and it is the same answer somebody typing `!twitch title` into a
 * Telegram group deserves when there is no Twitch side configured at all.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
trait UsesTwitch
{
    private function twitch(Context $context): TwitchConnector
    {
        $connector = $context->bot->connector(TwitchConnector::NAME);

        if (! $connector instanceof TwitchConnector) {
            throw new ActionError('the Twitch side is not connected, so that cannot be done right now.');
        }

        return $connector;
    }
}
