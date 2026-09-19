<?php

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\AlwaysMismatchingAuthorsAdapter;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\AlwaysMismatchingItemsAdapter;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Author;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\PostItem;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\TwoRelationshipsRecordForm;
use Livewire\Livewire;

test('undoing a generic form autosave is not cancelled by a concurrent change to an untouched relationship', function () {
    $post = Post::create(['title' => 'Post']);
    $first = Author::create(['name' => 'First']);
    $second = Author::create(['name' => 'Second']);
    $post->authors()->attach($first);
    $item = PostItem::create(['post_id' => $post->getKey(), 'label' => 'Original', 'position' => 1]);

    $page = Livewire::test(TwoRelationshipsRecordForm::class, ['record' => $post]);

    // Only the authors relationship is touched by this autosave.
    $page->set('data.authors', [$second->getKey()])->call('autosave');
    expect($post->fresh()->authors->modelKeys())->toBe([$second->getKey()]);

    // A second editor changes the untouched `items` relationship in between.
    $item->update(['label' => 'Changed elsewhere']);

    $page->call('undoAutosave');

    expect($post->fresh()->authors->modelKeys())->toBe([$first->getKey()])
        ->and(PostItem::query()->whereKey($item->getKey())->value('label'))->toBe('Changed elsewhere');
});

test('undo is still cancelled when the touched relationship changes concurrently', function () {
    $post = Post::create(['title' => 'Post']);
    $first = Author::create(['name' => 'First']);
    $second = Author::create(['name' => 'Second']);
    $third = Author::create(['name' => 'Third']);
    $post->authors()->attach($first);

    $page = Livewire::test(TwoRelationshipsRecordForm::class, ['record' => $post]);
    $page->set('data.authors', [$second->getKey()])->call('autosave');

    // A second editor changes the SAME relationship this autosave touched.
    $post->authors()->sync([$third->getKey()]);

    $page->call('undoAutosave');

    expect($post->fresh()->authors->modelKeys())->toBe([$third->getKey()]);
});

test('undoing a generic form autosave is not blocked by an external adapter for an untouched relationship', function () {
    config(['filament-autosave.external_undo_adapters' => [new AlwaysMismatchingItemsAdapter]]);

    $post = Post::create(['title' => 'Post']);
    $first = Author::create(['name' => 'First']);
    $second = Author::create(['name' => 'Second']);
    $post->authors()->attach($first);
    PostItem::create(['post_id' => $post->getKey(), 'label' => 'Original', 'position' => 1]);

    $page = Livewire::test(TwoRelationshipsRecordForm::class, ['record' => $post]);
    $page->set('data.authors', [$second->getKey()])->call('autosave');

    $page->call('undoAutosave');

    expect($post->fresh()->authors->modelKeys())->toBe([$first->getKey()]);
});

test('undo is still blocked by an external adapter mismatch on the touched relationship', function () {
    config(['filament-autosave.external_undo_adapters' => [new AlwaysMismatchingAuthorsAdapter]]);

    $post = Post::create(['title' => 'Post']);
    $first = Author::create(['name' => 'First']);
    $second = Author::create(['name' => 'Second']);
    $post->authors()->attach($first);

    $page = Livewire::test(TwoRelationshipsRecordForm::class, ['record' => $post]);
    $page->set('data.authors', [$second->getKey()])->call('autosave');

    $page->call('undoAutosave');

    expect($post->fresh()->authors->modelKeys())->toBe([$second->getKey()]);
});
