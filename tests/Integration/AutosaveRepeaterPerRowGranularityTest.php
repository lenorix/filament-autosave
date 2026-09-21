<?php

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms\DeepRelationshipRecordForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\PostItem;
use Livewire\Livewire;

// These two tests pin the *per-row* conflict and per-row undo granularity we
// want for relationship Repeaters. They are RED on purpose today: the dirty
// hash keys the whole top-level relationship field ("data.items") and
// dirty_only persists / undoes the whole touched top-level relationship
// subtree, so one tab editing row A clobbers (persist) or reverts (undo) the
// row a remote tab changed in the same relationship. They only turn GREEN
// once autosave carries, merges and undoes one repeater row at a time.

function seedTwoItemRepeater(): Post
{
    $post = Post::create(['title' => 'Post']);
    PostItem::create(['post_id' => $post->getKey(), 'label' => 'Item One', 'position' => 1]);
    PostItem::create(['post_id' => $post->getKey(), 'label' => 'Item Two', 'position' => 2]);

    return $post;
}

// Two tabs of the same user on the same record, each editing a *different* row
// of the same relationship Repeater with dirty_only on. Each row's save must
// carry only the row it changed, so neither clobbers the other.
test('a generic form repeater conflict is per row with dirty_only: two tabs editing different rows of the same relationship repeater both persist', function () {
    config(['filament-autosave.dirty_only' => true]);
    $post = seedTwoItemRepeater();

    $first = Livewire::test(DeepRelationshipRecordForm::class, ['record' => $post]);
    $second = Livewire::test(DeepRelationshipRecordForm::class, ['record' => $post]);

    $items = $first->get('data')['items'];
    [$firstKey, $secondKey] = array_keys($items);

    $first->set("data.items.{$firstKey}.label", 'Item One from A')->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');

    expect($post->fresh()->items()->orderBy('position')->pluck('label')->all())
        ->toBe(['Item One from A', 'Item Two']);

    // Editor B still holds the stale copy where row one is "Item One"; its save
    // must only carry the row it changed and not push row one back.
    $second->set("data.items.{$secondKey}.label", 'Item Two from B')->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');

    expect($post->fresh()->items()->orderBy('position')->pluck('label')->all())
        ->toBe(['Item One from A', 'Item Two from B']);

    // And A, still holding the stale row two, must not clobber it either.
    $first->set("data.items.{$firstKey}.label", 'Item One from A again')->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');

    expect($post->fresh()->items()->orderBy('position')->pluck('label')->all())
        ->toBe(['Item One from A again', 'Item Two from B']);
});

// While another tab edited a different row of the same relationship, undo here
// must only restore the row it wrote and keep its row, not treat the whole
// relationship as a single unit.
test('a generic form relationship undo is per row: it restores only the row it wrote and keeps another tab\'s remote row', function () {
    config(['filament-autosave.dirty_only' => true]);
    $post = seedTwoItemRepeater();

    $first = Livewire::test(DeepRelationshipRecordForm::class, ['record' => $post]);
    $second = Livewire::test(DeepRelationshipRecordForm::class, ['record' => $post]);

    $items = $first->get('data')['items'];
    [$firstKey, $secondKey] = array_keys($items);

    $first->set("data.items.{$firstKey}.label", 'Item One from A')->call('autosave')
        ->assertSet('autosaveCanUndo', true);

    $second->set("data.items.{$secondKey}.label", 'Item Two from B')->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');

    $first->call('undoAutosave')->assertDispatched('autosave-status', status: 'undone');

    expect($post->fresh()->items()->orderBy('position')->pluck('label')->all())
        ->toBe(['Item One', 'Item Two from B']);
});
