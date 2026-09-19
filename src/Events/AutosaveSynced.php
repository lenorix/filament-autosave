<?php

namespace Lenorix\FilamentAutosave\Events;

/**
 * A poll pulled another editor's changes into this component.
 */
final class AutosaveSynced
{
    /**
     * @param  array<string, mixed>  $refreshed  Clean fields refilled from the record, path => new value.
     * @param  array<int, string>  $stale  Fields dirty locally that also changed remotely; left untouched.
     */
    public function __construct(
        public readonly object $page,
        public readonly ?object $record,
        public readonly array $refreshed,
        public readonly array $stale,
    ) {}
}
