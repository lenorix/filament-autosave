<?php

use Lenorix\FilamentAutosave\AutosaveStatus;

test('the status event name is exposed once for every dispatch site', function () {
    expect(AutosaveStatus::EVENT)->toBe('autosave-status');
});

test('every status maps to the event value the browser understands', function () {
    $map = [
        [AutosaveStatus::Idle, 'idle'],
        [AutosaveStatus::DraftAvailable, 'draft_available'],
        [AutosaveStatus::Unsaved, 'unsaved'],
        [AutosaveStatus::Saving, 'saving'],
        [AutosaveStatus::Saved, 'saved'],
        [AutosaveStatus::Error, 'error'],
        [AutosaveStatus::Conflict, 'conflict'],
        [AutosaveStatus::Restored, 'restored'],
        [AutosaveStatus::Undone, 'undone'],
    ];

    foreach ($map as [$status, $value]) {
        expect($status->value)->toBe($value);
    }
});

test('from() resolves every supported browser status', function (string $value) {
    expect(AutosaveStatus::from($value))->toBeInstanceOf(AutosaveStatus::class);
})->with(['idle', 'draft_available', 'unsaved', 'saving', 'saved', 'error', 'conflict', 'restored', 'undone']);
