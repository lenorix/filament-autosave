<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Adapters;

use Lenorix\FilamentAutosave\Contracts\AutosaveExternalUndoAdapter;

class AlwaysMismatchingItemsAdapter implements AutosaveExternalUndoAdapter
{
    public function supports(object $field): bool
    {
        return method_exists($field, 'getRelationship') && $field->getRelationship() !== null;
    }

    public function snapshot(object $field): array
    {
        return [];
    }

    public function matches(object $field, array $snapshot): bool
    {
        return method_exists($field, 'getStatePath') && $field->getStatePath() !== 'data.items';
    }

    public function restore(object $field, array $snapshot): void {}
}
