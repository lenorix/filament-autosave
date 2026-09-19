<?php

namespace Lenorix\FilamentAutosave;

/**
 * What a poll or a save tells the browser about other editors, computed
 * away from Livewire so another transport can carry the same payload.
 *
 * Payload version 1:
 *
 *   synced: {v, refreshed: {path: value}, stale: [path], patches: {path: {theirs, hash}}, conflicts: {}}
 *   saved:  {v, merged: {path: value}, conflicts: {path: [{ours, theirs, position, reason}]}, patches: {path: {theirs, hash}}}
 *
 * `patches` carries the other editor's full current value for a dirty
 * mergeable field (never a base: the browser keeps its own). `hash` is
 * xxh128 of that value; a poll that receives the same hash back skips the
 * field. `conflicts[].reason` is `overlap` (resolved last-write-wins in that
 * range) or `contended` (left unwritten after every retry). A contended
 * field appears in both `merged` (the merge computed against the latest
 * value, for the browser to adopt) and `patches` (that latest value, for
 * the browser to take as its new base).
 *
 * @internal
 */
final class AutosaveSync
{
    public const VERSION = 1;

    public const OVERLAP = 'overlap';

    public const CONTENDED = 'contended';

    /** Fingerprint the browser echoes back so a poll can skip a value it already holds. */
    public static function hash(?string $value): string
    {
        return hash('xxh128', (string) $value);
    }

    /**
     * Sort a poll's remotely changed attributes into fields to refill, fields
     * to report stale and, among the latter, the mergeable ones whose value
     * the browser should receive.
     *
     * @param  list<string>  $changed  Attributes that changed since the last poll.
     * @param  array<string, mixed>  $current  Form values by top-level path.
     * @param  array<string, true>  $candidates  Clean paths that may be refilled.
     * @param  array<string, true>  $mergeable  Paths merged word by word.
     * @param  array<string, string>  $baseHashes  Hashes the browser holds, by path.
     * @param  array<string, mixed>  $attributes  The record's raw attributes.
     * @param  callable(string, mixed): bool  $isClean
     * @param  callable(string): bool  $isExcluded
     * @return array{refill: list<string>, stale: list<string>, patches: array<string, array{theirs: string, hash: string}>}
     */
    public static function plan(
        array $changed,
        array $current,
        array $candidates,
        array $mergeable,
        array $baseHashes,
        array $attributes,
        callable $isClean,
        callable $isExcluded,
    ): array {
        $refill = [];
        $stale = [];
        $patches = [];

        foreach ($changed as $attribute) {
            if (! array_key_exists($attribute, $current)) {
                continue;
            }

            if (isset($candidates[$attribute])) {
                $refill[] = $attribute;

                continue;
            }

            if ($isClean($attribute, $current[$attribute]) || $isExcluded($attribute)) {
                continue;
            }

            $stale[] = $attribute;

            if (! isset($mergeable[$attribute])) {
                continue;
            }

            $theirs = $attributes[$attribute] ?? null;
            $theirs = is_scalar($theirs) ? (string) $theirs : '';
            $hash = self::hash($theirs);

            if (($baseHashes[$attribute] ?? null) !== $hash) {
                $patches[$attribute] = ['theirs' => $theirs, 'hash' => $hash];
            }
        }

        sort($stale);

        return ['refill' => $refill, 'stale' => $stale, 'patches' => $patches];
    }

    /**
     * @param  array<string, mixed>  $refreshed
     * @param  list<string>  $stale
     * @param  array<string, array{theirs: string, hash: string}>  $patches
     * @return array<string, mixed>
     */
    public static function syncedPayload(array $refreshed, array $stale, array $patches): array
    {
        return [
            'v' => self::VERSION,
            'refreshed' => $refreshed,
            'stale' => $stale,
            'patches' => $patches,
            'conflicts' => [],
        ];
    }

    /**
     * @param  array<string, string>  $merged
     * @param  array<string, list<array{ours: string, theirs: string, position: int, reason: string}>>  $conflicts
     * @param  array<string, array{theirs: string, hash: string}>  $patches
     * @return array<string, mixed>
     */
    public static function savedPayload(array $merged, array $conflicts, array $patches): array
    {
        return [
            'v' => self::VERSION,
            'merged' => $merged,
            'conflicts' => $conflicts,
            'patches' => $patches,
        ];
    }
}
