<?php

namespace Lenorix\FilamentAutosave;

/**
 * Outcome of applying a client patch on top of the record's current text.
 * Offsets are code-point positions in `value`.
 *
 * @internal
 */
final class AutosaveApplyResult
{
    /**
     * @param  list<bool>  $applied  One flag per hunk: true when its context was found and the other editor left the hunk's text alone.
     * @param  list<array{ours: string, theirs: string, position: int}>  $conflicts  Hunks forced in over text the other editor changed; `theirs` is what was replaced.
     */
    public function __construct(
        public readonly string $value,
        public readonly array $applied,
        public readonly array $conflicts,
    ) {}
}
