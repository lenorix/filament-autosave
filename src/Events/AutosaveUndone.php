<?php

namespace Lenorix\FilamentAutosave\Events;

/** The previous autosave was reverted. */
final class AutosaveUndone
{
    public function __construct(
        public readonly object $page,
        public readonly ?object $record,
    ) {}
}
