<?php

use Illuminate\Support\Facades\Cache;
use Lenorix\FilamentAutosave\AutosaveExternalUndoManager;
use Lenorix\FilamentAutosave\Contracts\AutosaveExternalUndoAdapter;

beforeEach(function () {
    Cache::flush();
});

test('external undo adapters snapshot, match and restore supported fields', function () {
    $field = new class
    {
        public string $value = 'current';
    };

    $adapter = new class implements AutosaveExternalUndoAdapter
    {
        public function supports(object $field): bool
        {
            return property_exists($field, 'value');
        }

        public function snapshot(object $field): array
        {
            return ['value' => $field->value];
        }

        public function matches(object $field, array $snapshot): bool
        {
            return $field->value === ($snapshot['value'] ?? null);
        }

        public function restore(object $field, array $snapshot): void
        {
            $field->value = (string) $snapshot['value'];
        }
    };

    config(['filament-autosave.external_undo_adapters' => [$adapter]]);
    $manager = app(AutosaveExternalUndoManager::class);
    $snapshot = $manager->snapshot(['custom' => $field]);

    expect($snapshot['custom']['state'])->toBe(['value' => 'current'])
        ->and($manager->matches($snapshot, ['custom' => $field]))->toBeTrue();

    $field->value = 'changed';
    expect($manager->matches($snapshot, ['custom' => $field]))->toBeFalse();

    $manager->restore($snapshot, ['custom' => $field]);
    expect($field->value)->toBe('current');
});

test('unsupported external fields never report as reversible', function () {
    config(['filament-autosave.external_undo_adapters' => []]);

    expect(app(AutosaveExternalUndoManager::class)->hasUnsupported([
        new stdClass,
    ]))->toBeTrue();
});
