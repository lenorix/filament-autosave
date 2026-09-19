<?php

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\TwoColumnRecordForm;
use Livewire\Component;
use Livewire\Livewire;

// Mirror of "panel undo preserves a newer concurrent update" for generic
// forms: two live instances of the same component editing the same record.
test('two generic form instances editing different columns with dirty_only both persist without clobbering', function () {
    config(['filament-autosave.dirty_only' => true]);
    $post = Post::create(['title' => 'Original title', 'slug' => 'original-slug']);

    $first = Livewire::test(TwoColumnRecordForm::class, ['record' => $post]);
    $second = Livewire::test(TwoColumnRecordForm::class, ['record' => $post]);

    $first->set('data.title', 'Title from A')->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');
    expect($post->fresh()->only(['title', 'slug']))->toBe(['title' => 'Title from A', 'slug' => 'original-slug']);

    // B still holds the stale "Original title" locally; its write must only
    // carry the column it changed.
    $second->set('data.slug', 'slug-from-b')->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');

    expect($post->fresh()->only(['title', 'slug']))->toBe(['title' => 'Title from A', 'slug' => 'slug-from-b']);

    // And A, still holding the stale slug, must not push it back either.
    $first->set('data.title', 'Title from A again')->call('autosave');

    expect($post->fresh()->only(['title', 'slug']))->toBe(['title' => 'Title from A again', 'slug' => 'slug-from-b']);
});

// Two tabs of the same user on the same record must each own their Undo
// slot: the key carries the Livewire instance id (see AutosaveStore::undoCacheKey()).
test('a generic form undo only restores the column it wrote and keeps the other instance\'s column', function () {
    config(['filament-autosave.dirty_only' => true]);
    $post = Post::create(['title' => 'Original title', 'slug' => 'original-slug']);

    $first = Livewire::test(TwoColumnRecordForm::class, ['record' => $post]);
    $second = Livewire::test(TwoColumnRecordForm::class, ['record' => $post]);

    $first->set('data.title', 'Title from A')->call('autosave')->assertSet('autosaveCanUndo', true);
    $second->set('data.slug', 'slug-from-b')->call('autosave');

    $first->call('undoAutosave')->assertDispatched('autosave-status', status: 'undone');

    expect($post->fresh()->only(['title', 'slug']))->toBe(['title' => 'Original title', 'slug' => 'slug-from-b']);
});

test('an edit page undo only restores the column it wrote when another tab saved a different column', function () {
    config(['filament-autosave.dirty_only' => true]);
    $post = Post::create(['title' => 'Original title', 'slug' => 'original-slug']);

    $first = Livewire::test(EditPost::class, ['record' => $post->getKey()]);
    $second = Livewire::test(EditPost::class, ['record' => $post->getKey()]);

    $first->set('data.title', 'Title from A')->call('autosave')->assertSet('autosaveCanUndo', true);
    $second->set('data.slug', 'slug-from-b')->call('autosave');

    $first->call('undoAutosave')->assertDispatched('autosave-status', status: 'undone');

    expect($post->fresh()->only(['title', 'slug']))->toBe(['title' => 'Original title', 'slug' => 'slug-from-b']);
});
