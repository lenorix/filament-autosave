<?php

namespace Lenorix\FilamentAutosave\Events;

/**
 * Something could not be reconciled with another editor's write: Undo was
 * cancelled because the value it would restore changed elsewhere, or a
 * mergeable field stayed contended through every retry and was left
 * unwritten (`$conflicts` names it with `reason => 'contended'`).
 */
final class AutosaveConflict
{
    /**
     * @param  array<string, list<array<string, mixed>>>  $conflicts  `{ours, theirs, position, reason}`, plus `kind` and `block` for rich content. Empty for an Undo conflict.
     */
    public function __construct(
        public readonly object $page,
        public readonly ?object $record,
        public readonly array $conflicts = [],
    ) {}
}
