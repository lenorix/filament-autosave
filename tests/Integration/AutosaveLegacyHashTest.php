<?php

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms\LegacyHashRecordForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;
use Livewire\Livewire;

/**
 * Generic forms hashed fields with sha256 (64 hex chars) before the Undo
 * engine unified hashing on xxh128 (32 hex chars). A tab opened before that
 * deploy still carries the old hashes in its Livewire state. They must be
 * treated as "baseline unknown", not "dirty": otherwise the first autosave
 * after the deploy writes every field with the tab's stale values and
 * overwrites what another editor changed in the meantime.
 */
test('legacy sha256 field hashes never let a stale tab overwrite another editor', function () {
    config(['filament-autosave.dirty_only' => true]);
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    $page = Livewire::test(LegacyHashRecordForm::class, ['record' => $post]);
    $seeded = $page->get('autosaveFieldHashes');
    expect(strlen(reset($seeded)))->toBe(64);

    // Another editor changes slug after this tab loaded; this tab edits title.
    $post->update(['slug' => 'changed-elsewhere']);

    $page->set('data.title', 'Edited')->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');

    expect($post->fresh()->title)->toBe('Edited')
        ->and($post->fresh()->slug)->toBe('changed-elsewhere');

    foreach ($page->get('autosaveFieldHashes') as $hash) {
        expect(strlen($hash))->toBe(32);
    }
});

test('a real local change is still written when the other fields carry legacy hashes', function () {
    config(['filament-autosave.dirty_only' => true]);
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    $page = Livewire::test(LegacyHashRecordForm::class, ['record' => $post]);

    $page->set('data.title', 'Edited')->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');

    expect($post->fresh()->title)->toBe('Edited');
});
