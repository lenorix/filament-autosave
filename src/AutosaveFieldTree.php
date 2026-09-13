<?php

namespace Lenorix\FilamentAutosave;

use Illuminate\Support\Arr;

/**
 * Pure helpers for walking form state along dotted paths with wildcards.
 *
 * Row keys inside repeaters become a single wildcard segment, e.g. items.*.label.
 */
class AutosaveFieldTree
{
    /**
     * Swap repeating row keys for a wildcard while keeping named levels literal.
     *
     * @param  array<string, true>  $declared  Flat keys the form declares.
     */
    public static function normalizePath(string $key, array $declared): string
    {
        $segments = explode('.', $key);
        $prefix = [];
        $pattern = [];

        foreach ($segments as $segment) {
            $pattern[] = ($prefix !== [] && isset($declared[implode('.', $prefix)]))
                ? '*'
                : $segment;

            $prefix[] = $segment;
        }

        return implode('.', $pattern);
    }

    /**
     * First segment of a dotted path, e.g. `items.*.title` => `items`.
     */
    public static function topLevelKey(string $path): string
    {
        return explode('.', $path, 2)[0];
    }

    /**
     * Strip a state root from a full path, e.g. `data.items.0.name` with
     * root `data` => `items.0.name`. A path at the root itself yields ''.
     */
    public static function relativePath(string $path, string $root): string
    {
        $root = trim($root, '.');

        if ($path === $root) {
            return '';
        }

        if ($root !== '' && str_starts_with($path, $root.'.')) {
            return substr($path, strlen($root) + 1);
        }

        return $path;
    }

    /**
     * Turn dotted patterns into a nested tree; leaves are empty arrays.
     *
     * @param  array<string>  $patterns
     * @return array<string, mixed>
     */
    public static function buildTree(array $patterns): array
    {
        $tree = [];

        foreach ($patterns as $pattern) {
            $branch = &$tree;

            foreach (explode('.', $pattern) as $segment) {
                $branch[$segment] ??= [];
                $branch = &$branch[$segment];
            }

            unset($branch);
        }

        return $tree;
    }

    /**
     * Drop every state key the tree does not declare.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $tree
     * @return array<string, mixed>
     */
    public static function prune(array $state, array $tree): array
    {
        if ($tree === []) {
            return $state;
        }

        $pruned = [];

        foreach ($state as $key => $value) {
            $branch = $tree[$key] ?? $tree['*'] ?? null;

            if ($branch === null) {
                continue;
            }

            $pruned[$key] = is_array($value)
                ? self::prune($value, $branch)
                : $value;
        }

        return $pruned;
    }

    /**
     * Whether a concrete dotted key fits under a wildcarded pattern with the
     * same number of segments, e.g. `items.r2.label` matches `items.*.label`.
     */
    public static function matches(string $key, string $pattern): bool
    {
        $keySegments = explode('.', $key);
        $patternSegments = explode('.', $pattern);

        if (count($keySegments) !== count($patternSegments)) {
            return false;
        }

        foreach ($patternSegments as $index => $segment) {
            if ($segment !== '*' && $segment !== $keySegments[$index]) {
                return false;
            }
        }

        return true;
    }

    /**
     * List the concrete paths in $data that a wildcarded pattern matches.
     *
     * @param  array<string, mixed>  $data
     * @return array<string> concrete paths in $data matching a wildcarded pattern
     */
    public static function matchPaths(array $data, string $pattern): array
    {
        $paths = [''];

        foreach (explode('.', $pattern) as $segment) {
            $matched = [];

            foreach ($paths as $prefix) {
                $parent = $prefix === '' ? $data : data_get($data, $prefix);

                if (! is_array($parent)) {
                    continue;
                }

                $keys = $segment === '*'
                    ? array_keys($parent)
                    : (array_key_exists($segment, $parent) ? [$segment] : []);

                foreach ($keys as $key) {
                    $matched[] = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                }
            }

            if ($matched === []) {
                return [];
            }

            $paths = $matched;
        }

        return $paths;
    }

    /**
     * Run $use for every concrete path in $data that a declared field pattern matches.
     *
     * The data array is passed by reference so the callback can prune matches.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, array<int, mixed>>  $fields  path pattern => component instances
     * @param  callable(array<string, mixed> $data, array<int, mixed> $fieldSet, string $match): void  $use
     */
    public static function eachMatch(array &$data, array $fields, callable $use): void
    {
        foreach ($fields as $pattern => $fieldSet) {
            foreach (self::matchPaths($data, $pattern) as $match) {
                $use($data, $fieldSet, $match);
            }
        }
    }

    /**
     * Check whether a value fully satisfies a wildcarded path.
     */
    public static function isComplete(mixed $value, string $pattern): bool
    {
        $segments = explode('.', $pattern);

        while ($segments !== []) {
            $segment = array_shift($segments);

            if (! is_array($value)) {
                return false;
            }

            if ($segment === '*') {
                $remaining = implode('.', $segments);

                foreach ($value as $item) {
                    if (! self::isComplete($item, $remaining)) {
                        return false;
                    }
                }

                return true;
            }

            if (! array_key_exists($segment, $value)) {
                return false;
            }

            $value = $value[$segment];
        }

        return true;
    }

    /**
     * Remove a path along with any parents it leaves empty.
     *
     * @param  array<string, mixed>  $data
     */
    public static function forget(array &$data, string $path): void
    {
        Arr::forget($data, $path);

        $segments = explode('.', $path);
        array_pop($segments);

        while ($segments !== []) {
            $parent = implode('.', $segments);

            if (data_get($data, $parent) !== []) {
                return;
            }

            Arr::forget($data, $parent);
            array_pop($segments);
        }
    }
}
