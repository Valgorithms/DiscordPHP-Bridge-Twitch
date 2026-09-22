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

namespace TwitchBot\Support;

/**
 * Decides whether the application command Discord already has still matches
 * the one this build defines.
 *
 * Without this, a bot that registers a command only when it is missing keeps
 * whatever it published the first time: add a sub-command, restart, and the
 * new one is routed in code but never offered by Discord — the handler simply
 * cannot be reached. Re-creating the command on every boot is not the answer
 * either, since a global command takes up to an hour to propagate and the
 * write is rate limited.
 *
 * So the two definitions are compared, and only a real difference is
 * published. The comparison has to be lenient about everything Discord adds on
 * its way back — `id`, `application_id`, `version`, defaults it fills in, and
 * key order — or every restart would look like a change.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class CommandSync
{
    /**
     * The fields that actually describe a command. Anything else Discord
     * echoes back is ignored.
     */
    private const COMMAND_FIELDS = [
        'name',
        'description',
        'type',
        'options',
        'contexts',
        'integration_types',
        'default_member_permissions',
        'nsfw',
    ];

    /** The fields that describe one option. */
    private const OPTION_FIELDS = [
        'name',
        'description',
        'type',
        'required',
        'options',
        'choices',
        'channel_types',
        'min_value',
        'max_value',
        'min_length',
        'max_length',
        'autocomplete',
    ];

    /**
     * Whether the published command differs from the one just built.
     *
     * @param array<string, mixed> $published What Discord has.
     * @param array<string, mixed> $built     What this build defines.
     */
    public static function differs(array $published, array $built): bool
    {
        return self::canonical($published, self::COMMAND_FIELDS) !== self::canonical($built, self::COMMAND_FIELDS);
    }

    /**
     * Reduces a command payload to the part worth comparing: known fields
     * only, defaults made explicit, ordering made irrelevant.
     *
     * @param  array<string, mixed> $payload
     * @param  list<string>         $fields
     * @return array<string, mixed>
     */
    public static function canonical(array $payload, array $fields = self::COMMAND_FIELDS): array
    {
        $canonical = [];

        foreach ($fields as $field) {
            $value = $payload[$field] ?? null;

            if ($value instanceof \JsonSerializable) {
                $value = $value->jsonSerialize();
            }

            if (is_object($value)) {
                $value = (array) $value;
            }

            $canonical[$field] = match ($field) {
                // Discord omits a false `required`/`nsfw` rather than sending
                // it, so an omission and an explicit false are the same thing.
                'required', 'autocomplete', 'nsfw' => (bool) $value,
                // CHAT_INPUT is the default when the type is left out.
                'type' => $value === null ? 1 : (int) $value,
                'options' => self::canonicalOptions($value),
                // Sets, not lists: the order Discord returns them in is its
                // own business.
                'contexts', 'integration_types', 'channel_types' => self::canonicalSet($value),
                'choices' => self::canonicalChoices($value),
                default => $value === null || $value === '' ? null : $value,
            };
        }

        return $canonical;
    }

    /**
     * Options are ordered — a sub-command list is shown in the order it was
     * declared — so they are canonicalised in place rather than sorted.
     *
     * @return list<array<string, mixed>>
     */
    private static function canonicalOptions(mixed $options): array
    {
        $canonical = [];

        foreach (self::toArray($options) as $option) {
            $canonical[] = self::canonical(self::toArray($option), self::OPTION_FIELDS);
        }

        return $canonical;
    }

    /** @return list<array{name: string, value: mixed}> */
    private static function canonicalChoices(mixed $choices): array
    {
        $canonical = [];

        foreach (self::toArray($choices) as $choice) {
            $choice = self::toArray($choice);
            $canonical[] = ['name' => (string) ($choice['name'] ?? ''), 'value' => $choice['value'] ?? null];
        }

        return $canonical;
    }

    /** @return list<mixed> */
    private static function canonicalSet(mixed $values): array
    {
        $set = array_values(self::toArray($values));
        sort($set);

        return $set;
    }

    /**
     * Whatever DiscordPHP handed over — an array, a Collection, a Part, a
     * `stdClass` off the wire — as a plain array.
     *
     * @return array<array-key, mixed>
     */
    private static function toArray(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if ($value instanceof \JsonSerializable) {
            $value = $value->jsonSerialize();
        } elseif ($value instanceof \Traversable) {
            $value = iterator_to_array($value);
        }

        if (is_object($value)) {
            $value = (array) $value;
        }

        return is_array($value) ? $value : [];
    }
}
