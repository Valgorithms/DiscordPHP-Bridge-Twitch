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

use TwitchBot\Command\Surface;

/**
 * Renders an answer for whichever chat asked the question.
 *
 * The same action answers into a 2000-character Markdown-rendering Discord
 * channel and a 500-character flat-text Twitch one. Rather than every action
 * carrying a `if ($surface === ...)`, they hand structured values to these
 * helpers and let the surface decide.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Format
{
    /**
     * A set of labelled values: a fenced block on Discord, `a: b · c: d` on Twitch.
     *
     * @param array<string, string|int|float|bool|null> $fields
     */
    public static function fields(Surface $surface, array $fields, string $title = ''): string
    {
        $pairs = [];
        foreach ($fields as $label => $value) {
            $rendered = self::scalar($value);
            if ($rendered === '') {
                continue;
            }
            $pairs[(string) $label] = $rendered;
        }

        if ($pairs === []) {
            return $title === '' ? 'nothing to show' : $title . ': nothing to show';
        }

        if ($surface === Surface::Twitch) {
            $parts = [];
            foreach ($pairs as $label => $value) {
                $parts[] = $label . ': ' . $value;
            }

            return self::clamp(($title !== '' ? $title . ' — ' : '') . implode(' · ', $parts), $surface);
        }

        $width = max(array_map('mb_strlen', array_keys($pairs)));
        $lines = [];
        foreach ($pairs as $label => $value) {
            $lines[] = str_pad($label, $width) . '  ' . $value;
        }

        $body = "```\n" . implode("\n", $lines) . "\n```";

        return self::clamp($title === '' ? $body : '**' . $title . "**\n" . $body, $surface);
    }

    /**
     * A list of items, numbered on Discord and comma-joined on Twitch.
     *
     * @param list<string> $items
     */
    public static function listing(Surface $surface, array $items, string $title = '', string $empty = 'nothing to show'): string
    {
        $items = array_values(array_filter($items, static fn (string $i): bool => trim($i) !== ''));

        if ($items === []) {
            return $title === '' ? $empty : $title . ': ' . $empty;
        }

        if ($surface === Surface::Twitch) {
            return self::clamp(($title !== '' ? $title . ': ' : '') . implode(', ', $items), $surface);
        }

        $lines = [];
        foreach ($items as $i => $item) {
            $lines[] = ($i + 1) . '. ' . $item;
        }

        return self::clamp(($title !== '' ? '**' . $title . "**\n" : '') . implode("\n", $lines), $surface);
    }

    /** A block of preformatted text — fenced on Discord, inlined on Twitch. */
    public static function code(Surface $surface, string $text, string $language = ''): string
    {
        if ($surface === Surface::Twitch) {
            return self::clamp(MessageText::sanitizeIrc($text), $surface);
        }

        // Leave room for the fence itself, and neutralise any fence in the
        // payload so API output cannot break out of the block.
        $text = str_replace('```', "`\u{200B}``", $text);
        $fence = "```{$language}\n";
        $budget = $surface->limit() - mb_strlen($fence) - 4;

        return $fence . MessageText::truncate($text, max(1, $budget)) . "\n```";
    }

    /** Seconds as `3h 14m`, or `just now` under a minute. */
    public static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return 'just now';
        }

        $parts = [];
        $units = ['d' => 86400, 'h' => 3600, 'm' => 60];

        foreach ($units as $suffix => $size) {
            if ($seconds >= $size) {
                $parts[] = intdiv($seconds, $size) . $suffix;
                $seconds %= $size;
            }
        }

        return implode(' ', array_slice($parts, 0, 2));
    }

    /** `1.2k`, `3.4M` — viewer and follower counts get large. */
    public static function number(int|float $value): string
    {
        $value = (float) $value;

        return match (true) {
            abs($value) >= 1_000_000 => rtrim(rtrim(number_format($value / 1_000_000, 1), '0'), '.') . 'M',
            abs($value) >= 1_000 => rtrim(rtrim(number_format($value / 1_000, 1), '0'), '.') . 'k',
            default => (string) (int) $value,
        };
    }

    /** Clamps to the surface's limit, marking the truncation. */
    public static function clamp(string $text, Surface $surface): string
    {
        return MessageText::truncate($text, $surface->limit());
    }

    private static function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'yes' : 'no',
            is_float($value) => rtrim(rtrim(number_format($value, 2), '0'), '.'),
            default => trim((string) $value),
        };
    }
}
