<?php

namespace Lenorix\FilamentAutosave;

/**
 * Outcome of a three-way text merge. Offsets are code-point positions in
 * `value`.
 *
 * @internal
 */
final class AutosaveMergeResult
{
    /**
     * @param  list<array{position: int, from: string, to: string}>  $oursHunks  Our changes, all applied.
     * @param  list<array{position: int, from: string, to: string}>  $theirsHunks  Their changes that were applied.
     * @param  list<array{ours: string, theirs: string, position: int}>  $conflicts  Ranges both sides changed differently; `ours` is what `value` holds there.
     */
    public function __construct(
        public readonly string $value,
        public readonly array $oursHunks,
        public readonly array $theirsHunks,
        public readonly array $conflicts,
    ) {}
}
