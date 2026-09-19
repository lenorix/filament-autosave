<?php

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\PostItem;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\PostSubItem;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\RehydratingHookEditPost;
use Livewire\Livewire;

test('a new nested row survives a handleRecordUpdate() that refills the form without saving relationships', function () {
    $post = Post::create(['title' => 'Post']);
    $item = PostItem::create(['post_id' => $post->getKey(), 'label' => 'Item', 'position' => 1]);
    PostSubItem::create(['post_item_id' => $item->getKey(), 'label' => 'Existing']);

    $page = Livewire::test(RehydratingHookEditPost::class, ['record' => $post->getKey()]);
    $items = $page->get('data.items');
    $itemKey = array_key_first($items);
    $subitems = $items[$itemKey]['subitems'];
    $subitems['new-row'] = ['label' => 'Added', 'subsubitems' => []];

    $page->set('data.title', 'Changed')
        ->set("data.items.{$itemKey}.subitems", $subitems)
        ->call('flushAutosave');

    expect(PostSubItem::query()->where('post_item_id', $item->getKey())->pluck('label')->sort()->values()->all())
        ->toBe(['Added', 'Existing'])
        ->and($post->fresh()->title)->toBe('Changed');
});

test('a nested edit survives a handleRecordUpdate() that refills the form without saving relationships', function () {
    $post = Post::create(['title' => 'Post']);
    $item = PostItem::create(['post_id' => $post->getKey(), 'label' => 'Item', 'position' => 1]);
    $subitem = PostSubItem::create(['post_item_id' => $item->getKey(), 'label' => 'Original']);

    $page = Livewire::test(RehydratingHookEditPost::class, ['record' => $post->getKey()]);
    $items = $page->get('data.items');
    $itemKey = array_key_first($items);
    $subKey = array_key_first($items[$itemKey]['subitems']);

    $page->set('data.title', 'Changed')
        ->set("data.items.{$itemKey}.subitems.{$subKey}.label", 'Edited')
        ->call('flushAutosave');

    expect($subitem->fresh()->label)->toBe('Edited')
        ->and(PostSubItem::query()->where('post_item_id', $item->getKey())->count())->toBe(1);
});

test('a new top-level row survives a handleRecordUpdate() that refills the form without saving relationships', function () {
    $post = Post::create(['title' => 'Post']);
    PostItem::create(['post_id' => $post->getKey(), 'label' => 'Existing', 'position' => 1]);

    $page = Livewire::test(RehydratingHookEditPost::class, ['record' => $post->getKey()]);
    $items = $page->get('data.items');
    $items['new-row'] = ['label' => 'Added', 'position' => 2, 'subitems' => []];

    $page->set('data.title', 'Changed')->set('data.items', $items)->call('flushAutosave');

    expect(PostItem::query()->where('post_id', $post->getKey())->pluck('label')->sort()->values()->all())
        ->toBe(['Added', 'Existing']);
});
