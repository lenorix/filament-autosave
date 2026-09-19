<?php

namespace Lenorix\FilamentAutosave;

use Illuminate\Support\Facades\Cache;

/**
 * One-step Undo target: the six cached snapshot parts and the optimistic
 * checks against them. Shared by Edit pages and generic forms so both sides
 * store, compare and clear the same shape under the same rules.
 *
 * "Before" parts hold what an autosave is about to overwrite; "expected"
 * parts hold what it actually wrote, so a later Undo can refuse when someone
 * else changed the record in between.
 *
 * @internal
 */
final class AutosaveUndo
{
    public const VALUES = 'values';

    public const RELATIONSHIPS = 'relationships';

    public const EXTERNAL = 'external';

    public const EXPECTED = 'expected';

    public const EXPECTED_RELATIONSHIPS = 'expected-relationships';

    public const EXPECTED_EXTERNAL = 'expected-external';

    /** @var list<string> */
    public const PARTS = [
        self::VALUES,
        self::RELATIONSHIPS,
        self::EXPECTED,
        self::EXPECTED_RELATIONSHIPS,
        self::EXTERNAL,
        self::EXPECTED_EXTERNAL,
    ];

    /**
     * @param  string  $baseKey  Per-scope, per-record, per-instance key prefix.
     * @param  bool  $bareValuesKey  Edit pages store the column snapshot at the
     *                               bare base key (historical); other parts and
     *                               generic forms use `{base}:{part}`.
     */
    public function __construct(
        private readonly string $baseKey,
        private readonly int $ttlMinutes,
        private readonly bool $bareValuesKey = false,
    ) {}

    public function key(string $part): string
    {
        if ($part === self::VALUES && $this->bareValuesKey) {
            return $this->baseKey;
        }

        return $this->baseKey.':'.$part;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_map($this->key(...), self::PARTS);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return bool Whether anything was stored.
     */
    public function put(string $part, array $value): bool
    {
        if ($value === []) {
            return false;
        }

        Cache::put($this->key($part), $value, now()->addMinutes($this->ttlMinutes));

        return true;
    }

    /** @return array<string, mixed>|null */
    public function get(string $part): ?array
    {
        $value = Cache::get($this->key($part));

        return is_array($value) ? $value : null;
    }

    /** Whether any "before" part holds something to restore. */
    public function hasSnapshot(): bool
    {
        foreach ([self::VALUES, self::RELATIONSHIPS, self::EXTERNAL] as $part) {
            if (($this->get($part) ?? []) !== []) {
                return true;
            }
        }

        return false;
    }

    public function clear(): void
    {
        foreach ($this->keys() as $key) {
            Cache::forget($key);
        }
    }

    /**
     * Column values as written by the autosave still match the record.
     * A missing expectation never counts as a conflict.
     *
     * @param  array<string, mixed>|null  $expected  Normalized column snapshot.
     * @param  array<string, mixed>  $current  Normalized current values for the same keys.
     */
    public static function columnsMatch(?array $expected, array $current): bool
    {
        return $expected === null || $current === $expected;
    }

    /**
     * Relationship rows as written by the autosave still match the database.
     * Only the paths this autosave touched are compared.
     *
     * @param  array<string, array<string, mixed>>|null  $expected
     * @param  array<string, array<string, mixed>>  $current
     */
    public static function relationshipsMatch(?array $expected, array $current): bool
    {
        return $expected === null || array_intersect_key($current, $expected) === $expected;
    }
}
