<?php

namespace Lenorix\FilamentAutosave\Contracts;

interface AutosaveExternalUndoAdapter
{
    public function supports(object $field): bool;

    /** @return array<string, mixed> */
    public function snapshot(object $field): array;

    /** @param array<string, mixed> $snapshot */
    public function matches(object $field, array $snapshot): bool;

    /** @param array<string, mixed> $snapshot */
    public function restore(object $field, array $snapshot): void;
}
