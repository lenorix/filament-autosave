<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\AutosaveUploadRecordForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;
use Livewire\Livewire;

/**
 * The browser controller watches `autosaveObservedHash` to notice state the
 * deep `data` watcher cannot see, above all upload state. Edit pages fold
 * every upload field's hash into it (`dehydrateHasAutosave()`); generic forms
 * must do the same or an upload-only server-side change never reopens a save.
 */
test('a generic form observed hash changes when only an upload field changes', function () {
    Storage::fake('public');
    $post = Post::create(['title' => 'Post']);

    $page = Livewire::test(AutosaveUploadRecordForm::class, ['record' => $post]);
    $before = $page->get('autosaveObservedHash');

    $page->set('data.settings', [UploadedFile::fake()->create('document.txt', 1)]);

    expect($page->get('autosaveObservedHash'))->not->toBe($before);
});

test('a generic form observed hash is stable while nothing changes', function () {
    $post = Post::create(['title' => 'Post']);

    $page = Livewire::test(AutosaveUploadRecordForm::class, ['record' => $post]);
    $before = $page->get('autosaveObservedHash');

    $page->call('$refresh');

    expect($page->get('autosaveObservedHash'))->toBe($before);
});
