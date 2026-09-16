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
 * A group of related actions.
 *
 * Providers exist so a feature arrives as one file — its commands, their
 * permissions and their help text together — rather than as scattered
 * registration calls whose ordering matters.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
interface ActionProvider
{
    /** @return list<Action> */
    public function actions(): array;
}
