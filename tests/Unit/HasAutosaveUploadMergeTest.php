<?php

use Filament\Forms\Components\FileUpload;
use Illuminate\Support\Facades\Storage;
use Lenorix\FilamentAutosave\Tests\Fixtures\AutosaveUploadMergeColumnRecord;
use Lenorix\FilamentAutosave\Tests\Fixtures\AutosaveUploadMergeProbe;

test('a fresh upload merge keeps column files that are still stored on disk', function () {
    Storage::fake('public');
    Storage::disk('public')->put('existing.txt', 'existing');
    $probe = new AutosaveUploadMergeProbe(new AutosaveUploadMergeColumnRecord(['settings' => ['existing.txt']]));

    $merged = (fn (FileUpload $field, string $path, array $stored) => $this->mergeAutosaveUploadedPaths($field, $path, $stored))
        ->call($probe, FileUpload::make('settings')->multiple()->disk('public'), 'settings', ['document.txt']);

    expect($merged)->toBe(['document.txt', 'existing.txt']);
});

test('the upload merge skips column paths that no longer exist on disk', function () {
    Storage::fake('public');
    Storage::disk('public')->put('existing.txt', 'existing');
    $probe = new AutosaveUploadMergeProbe(new AutosaveUploadMergeColumnRecord(['settings' => ['existing.txt', 'removed.txt']]));

    $merged = (fn (FileUpload $field, string $path, array $stored) => $this->mergeAutosaveUploadedPaths($field, $path, $stored))
        ->call($probe, FileUpload::make('settings')->multiple()->disk('public'), 'settings', ['document.txt']);

    expect($merged)->toBe(['document.txt', 'existing.txt']);
});

test('the upload merge does not duplicate paths already present in fresh state', function () {
    Storage::fake('public');
    Storage::disk('public')->put('existing.txt', 'existing');
    $probe = new AutosaveUploadMergeProbe(new AutosaveUploadMergeColumnRecord(['settings' => ['existing.txt']]));

    $merged = (fn (FileUpload $field, string $path, array $stored) => $this->mergeAutosaveUploadedPaths($field, $path, $stored))
        ->call($probe, FileUpload::make('settings')->multiple()->disk('public'), 'settings', ['existing.txt', 'document.txt']);

    expect($merged)->toBe(['existing.txt', 'document.txt']);
});