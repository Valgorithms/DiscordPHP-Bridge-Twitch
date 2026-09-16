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
 * The arguments to one command invocation, parsed the same way on both surfaces.
 *
 * The two clients hand over tokens that were split by different rules —
 * DiscordPHP runs the line through `str_getcsv`, TwitchPHP splits on runs of
 * whitespace — so tokens are re-joined and re-parsed here. Without that,
 * `!title "on the road"` would mean two different things depending on where it
 * was typed, which is exactly the kind of difference this project exists to
 * avoid.
 *
 * Supports positional arguments and `key=value` flags, either of which may be
 * quoted:
 *
 * ```
 * !api channels.modify title="Back soon" game_id=509658
 *      └─ positional ─┘ └────── named ──────────────────┘
 * ```
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class Arguments
{
    /**
     * @param list<string>          $positional
     * @param array<string, string> $named
     * @param string|null           $raw The line as typed, or `null` when the
     *                                   values did not come from one.
     */
    private function __construct(
        private readonly array $positional,
        private readonly array $named,
        private readonly ?string $raw,
    ) {
    }

    /**
     * Builds from whatever tokens a client handed over.
     *
     * @param list<string> $tokens
     */
    public static function fromTokens(array $tokens): self
    {
        return self::fromString(implode(' ', array_map(static fn ($t): string => (string) $t, $tokens)));
    }

    /**
     * Builds from values that were already separated — a slash command's
     * options, which Discord has parsed and typed for us.
     *
     * Deliberately not routed through {@see fromString()}: a value containing
     * a space would be re-split by the tokenizer, and one containing a quote
     * would be mangled. The values are taken as given.
     *
     * Each option is recorded both positionally, in declaration order, and by
     * name — so a handler written for `!relay link twitchdev` reading `get(1)`
     * and one reading `named('twitch')` both work against the same invocation.
     *
     * @param list<string>          $positional
     * @param array<string, string> $named
     */
    public static function fromParts(array $positional, array $named = []): self
    {
        $keyed = [];
        foreach ($named as $key => $value) {
            $keyed[strtolower((string) $key)] = (string) $value;
        }

        return new self(array_values(array_map('strval', $positional)), $keyed, null);
    }

    public static function fromString(string $line): self
    {
        $positional = [];
        $named = [];

        foreach (self::tokenize($line) as $token) {
            // Only split on the first '=', and only when something precedes it
            // that looks like a flag name — so a positional argument that
            // merely contains '=' (a URL with a query string, say) stays whole.
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_.-]*)=(.*)$/s', $token, $m) === 1) {
                $named[strtolower($m[1])] = self::unquote($m[2]);

                continue;
            }

            $positional[] = self::unquote($token);
        }

        return new self($positional, $named, trim($line));
    }

    /** The nth positional argument, or `$default`. */
    public function get(int $index, ?string $default = null): ?string
    {
        return $this->positional[$index] ?? $default;
    }

    /** A `key=value` flag, or `$default`. */
    public function named(string $key, ?string $default = null): ?string
    {
        return $this->named[strtolower($key)] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->named[strtolower($key)]);
    }

    /**
     * Everything from `$index` onwards as one string, quotes and all.
     *
     * This is what free-text actions want: `!title` takes a sentence, not a
     * list of words, and re-joining the tokens would drop the user's own
     * spacing and quotes.
     *
     * When the arguments came from a slash command there is no line to slice —
     * the values arrived already separated and typed — so the positional values
     * are joined instead. Splitting and re-joining those would corrupt any
     * value containing a space, which for `/title text:Back in ten` is the
     * whole point of it.
     */
    public function rest(int $index = 0): string
    {
        if ($this->raw === null) {
            return trim(implode(' ', array_slice($this->positional, $index)));
        }

        if ($index === 0) {
            return $this->raw;
        }

        return trim(implode(' ', array_slice(self::tokenize($this->raw), $index)));
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->positional;
    }

    /** @return array<string, string> */
    public function allNamed(): array
    {
        return $this->named;
    }

    public function count(): int
    {
        return count($this->positional);
    }

    public function isEmpty(): bool
    {
        return $this->positional === [] && $this->named === [];
    }

    /**
     * Splits on whitespace, keeping quoted runs together.
     *
     * A token is any run of unquoted non-space characters and quoted segments,
     * in any order — which is what makes `title="on the road"` one token rather
     * than `title="on` and `road"`. A simpler `"..."|\S+` alternation gets that
     * wrong, because `\S+` matches from the start of the word and stops at the
     * first space, having already swallowed the opening quote.
     *
     * Quotes are kept in the token at this stage so `rest()` can hand back what
     * was actually typed; {@see unquote()} strips them when a single value is
     * read out.
     *
     * @return list<string>
     */
    private static function tokenize(string $line): array
    {
        preg_match_all('/(?:[^\s"\']+|"[^"]*"|\'[^\']*\')+/', $line, $matches);

        return array_values($matches[0] ?? []);
    }

    private static function unquote(string $value): string
    {
        $length = strlen($value);

        if ($length >= 2
            && (($value[0] === '"' && $value[$length - 1] === '"')
                || ($value[0] === "'" && $value[$length - 1] === "'"))) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
