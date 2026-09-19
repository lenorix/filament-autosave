<?php

use Lenorix\FilamentAutosave\AutosaveStatus;

test('every browser-facing status ships a translated label', function () {
    foreach ([
        AutosaveStatus::DraftAvailable,
        AutosaveStatus::Unsaved,
        AutosaveStatus::Saving,
        AutosaveStatus::Saved,
        AutosaveStatus::Error,
        AutosaveStatus::Conflict,
        AutosaveStatus::Restored,
        AutosaveStatus::Undone,
    ] as $status) {
        expect(Lang::has("filament-autosave::autosave.{$status->value}"))->toBeTrue();
    }
});

test('settled statuses leave the form aligned with the server state', function () {
    expect(AutosaveStatus::settledStatuses())->toBe([
        AutosaveStatus::Saved->value,
        AutosaveStatus::Idle->value,
        AutosaveStatus::Restored->value,
        AutosaveStatus::Undone->value,
    ]);
});

test('save-result statuses are the outcomes a save request can settle on', function () {
    expect(AutosaveStatus::saveResultStatuses())->toBe([
        AutosaveStatus::Saved->value,
        AutosaveStatus::Idle->value,
    ]);
});

test('transient statuses fade back to idle after a readable delay', function () {
    expect(AutosaveStatus::fadeMsByStatus())->toBe([
        AutosaveStatus::Saved->value => 5000,
        AutosaveStatus::Restored->value => 3000,
        AutosaveStatus::Undone->value => 3000,
        AutosaveStatus::Synced->value => 5000,
    ]);
});

test('statusMeta bundles everything the Alpine controller needs from PHP', function () {
    expect(AutosaveStatus::statusMeta())->toBe([
        'event' => AutosaveStatus::EVENT,
        'idle' => AutosaveStatus::Idle->value,
        'unsaved' => AutosaveStatus::Unsaved->value,
        'saving' => AutosaveStatus::Saving->value,
        'saved' => AutosaveStatus::Saved->value,
        'draftAvailable' => AutosaveStatus::DraftAvailable->value,
        'error' => AutosaveStatus::Error->value,
        'conflict' => AutosaveStatus::Conflict->value,
        'validation' => AutosaveStatus::Validation->value,
        'restored' => AutosaveStatus::Restored->value,
        'undone' => AutosaveStatus::Undone->value,
        'synced' => AutosaveStatus::Synced->value,
        'settled' => AutosaveStatus::settledStatuses(),
        'saveResults' => AutosaveStatus::saveResultStatuses(),
        'fadeMs' => AutosaveStatus::fadeMsByStatus(),
    ]);
});
