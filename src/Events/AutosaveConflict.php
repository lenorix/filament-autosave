<?php

namespace Lenorix\FilamentAutosave\Events;

/**
 * Undo was cancelled because the value it would restore was changed
 * elsewhere after the autosave wrote it.
 */
final class AutosaveConflict
{
    public function __construct(
        public readonly object $page,
        public readonly ?object $record,
    ) {}
}
