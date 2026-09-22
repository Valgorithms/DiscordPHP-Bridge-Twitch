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

namespace Bridge\Twitch\Api;

use Bridge\Command\ActionError;
use React\Promise\PromiseInterface;
use Twitch\Twitch;

/**
 * Reaches every method of every TwitchPHP repository from a line of chat.
 *
 * This is how the bot covers the whole Helix surface without thirty hand-written
 * commands per repository. A call is written `repository.method`, and arguments
 * are matched to the method's parameters *by name*, which is what makes it
 * usable rather than a positional guessing game:
 *
 * ```
 * api channels.modify broadcaster_id=29034572 fields={"title":"Back in ten"}
 * api search.categories query=minecraft first=5
 * ```
 *
 * The repository list is read out of `Twitch` by reflection rather than copied,
 * so a repository added to TwitchPHP tomorrow is reachable here without this
 * file being touched. Parameter names, types and defaults come from reflection
 * too, which is also where `api help <call>` gets its signatures — there is no
 * second catalogue to drift out of date.
 *
 * Every caller of this class is gated to {@see \Bridge\Command\Access::Owner}.
 * It can ban users, end streams and rewrite a channel; it is not a chat toy.
 *
 * @author Valithor Obsidion <valithor@valgorithms.com>
 */
final class RepositoryDispatcher
{
    /**
     * Methods inherited from {@see AbstractRepository} are hidden.
     *
     * They are collection plumbing — `filter()`, `first()`, `count()`,
     * `getIterator()` — operating on whatever happens to be cached in memory,
     * which is not what someone typing `api users.filter` expects, and `save()`
     * and `delete()` take a Part that cannot be built from a line of chat. The
     * genuinely useful lookups all exist as named methods on the repositories
     * themselves (`users.fetchByLogin`, `games.byIds`), so nothing is lost.
     *
     * Filtered by *declaring class* rather than by name, so a repository that
     * overrides one with a real endpoint keeps it.
     */
    private const HIDDEN_NAMES = ['__construct', '__get', '__set', '__call', 'getTwitch', 'jsonSerialize'];

    /** @var array<string, class-string>|null */
    private static ?array $repositories = null;

    public function __construct(private readonly Twitch $twitch)
    {
    }

    /**
     * Invokes `repository.method` with named arguments.
     *
     * @param array<string, string> $arguments
     *
     * @throws ActionError when the call does not exist or cannot be satisfied.
     *
     * @return PromiseInterface<mixed>
     */
    public function call(string $call, array $arguments): PromiseInterface
    {
        [$repositoryName, $methodName] = $this->split($call);

        $canonical = $this->canonical($repositoryName) ?? throw new ActionError($this->unknownRepository($repositoryName));
        $repository = $this->twitch->{$canonical};
        $method = $this->method($repository::class, $canonical, $methodName);

        return $repository->{$method->getName()}(...$this->bind($method, $arguments, $canonical));
    }

    /**
     * Every repository name, sorted.
     *
     * @return list<string>
     */
    public function repositories(): array
    {
        $names = array_keys(self::map());
        sort($names);

        return $names;
    }

    /**
     * Every callable method on one repository, sorted.
     *
     * @return list<string>
     *
     * @throws ActionError
     */
    public function methods(string $repositoryName): array
    {
        $canonical = $this->canonical($repositoryName) ?? throw new ActionError($this->unknownRepository($repositoryName));
        $class = self::map()[$canonical];

        $names = [];

        foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (self::isHidden($method)) {
                continue;
            }

            $names[] = $method->getName();
        }

        sort($names);

        return $names;
    }

    /**
     * A human-readable signature for one call, e.g.
     * `channels.modify broadcasterId=<string> fields=<array>`.
     *
     * @throws ActionError
     */
    public function signature(string $call): string
    {
        [$repositoryName, $methodName] = $this->split($call);
        $canonical = $this->canonical($repositoryName) ?? throw new ActionError($this->unknownRepository($repositoryName));

        $method = $this->method(self::map()[$canonical], $canonical, $methodName);
        $parts = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();
            $name = $parameter->getName() . '=<' . ($type instanceof \ReflectionNamedType ? $type->getName() : 'mixed') . '>';

            $parts[] = $parameter->isOptional() ? '[' . $name . ']' : $name;
        }

        return $canonical . '.' . $method->getName() . ($parts === [] ? '' : ' ' . implode(' ', $parts));
    }

    /** @return array{0: string, 1: string} */
    private function split(string $call): array
    {
        $call = trim($call);

        // Accept `channels.modify` and `channels modify` alike; the latter is
        // what someone types when they have just read `api list channels`.
        $parts = preg_split('/[.\s:\/]+/', $call, 2) ?: [];

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new ActionError('a call looks like `repository.method`, for example `channels.modify`. Try `api list` to see what exists.');
        }

        return [$parts[0], $parts[1]];
    }

    private function method(string $class, string $canonical, string $methodName): \ReflectionMethod
    {
        foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (self::isHidden($method)) {
                continue;
            }

            if (strcasecmp($method->getName(), $methodName) === 0) {
                return $method;
            }
        }

        $suggestion = $this->closest($methodName, $this->methods($canonical));

        throw new ActionError(sprintf(
            'no method `%s` on `%s`.%s',
            $methodName,
            $canonical,
            $suggestion !== null
                ? ' Did you mean `' . $suggestion . '`?'
                : ' Try `api list ' . $canonical . '`.',
        ));
    }

    /**
     * Matches supplied arguments to a method's parameters by name, coercing
     * each into the declared type.
     *
     * Named-only. Positional arguments are deliberately not accepted: a
     * mis-ordered `ban(broadcasterId, moderatorId, userId)` would ban the wrong
     * person, silently and irreversibly, and no amount of convenience is worth
     * that.
     *
     * @param array<string, string> $arguments
     *
     * @return list<mixed>
     */
    private function bind(\ReflectionMethod $method, array $arguments, string $canonical): array
    {
        $supplied = [];
        foreach ($arguments as $key => $value) {
            $supplied[self::normalizeKey($key)] = $value;
        }

        $bound = [];
        $consumed = [];

        foreach ($method->getParameters() as $parameter) {
            $key = self::normalizeKey($parameter->getName());

            if (! array_key_exists($key, $supplied)) {
                if ($parameter->isOptional()) {
                    // Stop at the first absent optional: anything after it
                    // would have to be passed positionally, and a gap would
                    // shift every later argument along by one.
                    break;
                }

                throw new ActionError(sprintf(
                    'missing `%s`. Usage: `%s`',
                    $parameter->getName(),
                    $this->signature($canonical . '.' . $method->getName()),
                ));
            }

            $bound[] = self::coerce($supplied[$key], $parameter);
            $consumed[] = $key;
        }

        $unknown = array_diff(array_keys($supplied), $consumed);
        if ($unknown !== []) {
            // Silently dropping these would look like the call worked while
            // doing something other than what was asked.
            throw new ActionError(sprintf(
                'unknown argument%s: %s. Usage: `%s`',
                count($unknown) === 1 ? '' : 's',
                implode(', ', $unknown),
                $this->signature($canonical . '.' . $method->getName()),
            ));
        }

        return $bound;
    }

    /**
     * Turns a chat string into the parameter's declared type.
     *
     * Everything arrives as text, so an `int $first` has to be made into one.
     * A wrong type here surfaces as an opaque Helix 400, so it is worth being
     * strict and explaining the failure in chat instead.
     */
    private static function coerce(string $value, \ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();
        $name = $type instanceof \ReflectionNamedType ? $type->getName() : 'string';

        if ($type instanceof \ReflectionNamedType && $type->allowsNull() && strcasecmp($value, 'null') === 0) {
            return null;
        }

        // A parameter typed as a class wants an object — a Part, a builder —
        // and there is no way to conjure one from a line of chat. Say so,
        // rather than passing a string and letting it surface as a TypeError
        // that reads like the bot is broken.
        if ($type instanceof \ReflectionNamedType && ! $type->isBuiltin()) {
            throw new ActionError(sprintf(
                '`%s` takes a %s object, which cannot be built from chat. Use a dedicated command for this one.',
                $parameter->getName(),
                $name,
            ));
        }

        return match ($name) {
            'int' => self::toInt($value, $parameter),
            'float' => is_numeric($value)
                ? (float) $value
                : throw new ActionError('`' . $parameter->getName() . '` must be a number, got `' . $value . '`.'),
            'bool' => self::toBool($value, $parameter),
            'array' => self::toArray($value),
            default => $value,
        };
    }

    private static function toInt(string $value, \ReflectionParameter $parameter): int
    {
        if (preg_match('/^-?\d+$/', trim($value)) !== 1) {
            throw new ActionError('`' . $parameter->getName() . '` must be a whole number, got `' . $value . '`.');
        }

        return (int) $value;
    }

    private static function toBool(string $value, \ReflectionParameter $parameter): bool
    {
        return match (strtolower(trim($value))) {
            'true', '1', 'yes', 'on' => true,
            'false', '0', 'no', 'off' => false,
            default => throw new ActionError('`' . $parameter->getName() . '` must be true or false, got `' . $value . '`.'),
        };
    }

    /**
     * JSON when it looks like JSON, otherwise a comma-separated list.
     *
     * Both are needed. `ids=1,2,3` is what a list parameter wants, but
     * `fields={"title":"x"}` is what the several `array $fields` parameters —
     * `channels.modify` among them — actually take.
     *
     * @return array<mixed>
     */
    private static function toArray(string $value): array
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return [];
        }

        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            $decoded = json_decode($trimmed, true);

            if (! is_array($decoded)) {
                throw new ActionError('that looks like JSON but will not parse: ' . json_last_error_msg());
            }

            return $decoded;
        }

        return array_values(array_map('trim', explode(',', $trimmed)));
    }

    /**
     * Whether a method is plumbing rather than an endpoint.
     *
     * Anything declared on the base repository is hidden — see
     * {@see HIDDEN_NAMES} for why — but a repository that *overrides* one is
     * offering a real endpoint under that name, so the check is on where the
     * method was declared, not merely what it is called.
     */
    private static function isHidden(\ReflectionMethod $method): bool
    {
        if ($method->isStatic() || in_array($method->getName(), self::HIDDEN_NAMES, true)) {
            return true;
        }

        return $method->getDeclaringClass()->getName() === \Twitch\Repository\AbstractRepository::class;
    }

    /** Case- and underscore-insensitive, so `broadcasterId` and `broadcaster_id` both land. */
    private static function normalizeKey(string $key): string
    {
        return strtolower(str_replace('_', '', trim($key)));
    }

    private function canonical(string $name): ?string
    {
        $needle = self::normalizeKey($name);

        foreach (array_keys(self::map()) as $candidate) {
            if (self::normalizeKey($candidate) === $needle) {
                return $candidate;
            }
        }

        return null;
    }

    private function unknownRepository(string $name): string
    {
        $suggestion = $this->closest($name, $this->repositories());

        return sprintf(
            'no repository `%s`.%s',
            $name,
            $suggestion !== null
                ? ' Did you mean `' . $suggestion . '`?'
                : ' Try `api list` for the full set.',
        );
    }

    /**
     * The nearest candidate, when one is near enough to be worth offering.
     *
     * @param list<string> $candidates
     */
    private function closest(string $needle, array $candidates): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($candidates as $candidate) {
            $distance = levenshtein(strtolower($needle), strtolower($candidate));

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        // Only suggest a genuinely near miss; an unrelated word is noise.
        return $bestDistance <= max(2, (int) floor(strlen($needle) / 3)) ? $best : null;
    }

    /**
     * The repository map, read out of `Twitch` itself.
     *
     * Reflection rather than a copied list: the constant is private, but a
     * duplicate here would silently stop covering repositories added later,
     * and covering all of them is the entire point of this class.
     *
     * @return array<string, class-string>
     */
    private static function map(): array
    {
        if (self::$repositories !== null) {
            return self::$repositories;
        }

        $constants = (new \ReflectionClass(Twitch::class))->getConstants();
        $map = $constants['REPOSITORIES'] ?? [];

        return self::$repositories = is_array($map) ? $map : [];
    }
}
