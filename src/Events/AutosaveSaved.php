<?php

namespace Lenorix\FilamentAutosave\Events;

/**
 * An autosave cycle wrote something: record columns and relationships on
 * Edit pages and record-backed forms, or a cached draft otherwise.
 */
final class AutosaveSaved
{
    /**
     * @param  array<string, mixed>  $data  The payload the cycle persisted.
     * @param  array<int, string>  $pending  Fields skipped by this cycle, if any.
     */
    public function __construct(
        public readonly object $page,
        public readonly ?object $record,
        public readonly array $data,
        public readonly array $pending,
    ) {}
}
