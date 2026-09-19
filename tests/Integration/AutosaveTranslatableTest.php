<?php

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\PostItem;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\PostSubItem;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\TranslatableEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\TranslatablePost;
use Livewire\Livewire;

function makeTranslatablePost(): TranslatablePost
{
    $post = new TranslatablePost;
    $post->setTranslations('title', ['en' => 'Hello', 'es' => 'Hola']);
    $post->save();

    return $post;
}

test('the translatable concern really re-saves relationships from handleRecordUpdate()', function () {
    $post = makeTranslatablePost();
    PostItem::create(['post_id' => $post->getKey(), 'label' => 'Item', 'position' => 1]);
    $saves = 0;
    PostItem::saved(function () use (&$saves): void {
        $saves++;
    });

    Livewire::test(TranslatableEditPost::class, ['record' => $post->getKey()])
        ->assertSet('activeLocale', 'en')
        ->assertSet('otherLocaleData', ['es' => ['title' => 'Hola']])
        ->set('data.title', 'Hello!')
        ->call('flushAutosave');

    // Only `title` changed, so the package's own relationship pass has nothing
    // pending for `items`. lara-zeus 2.x loops the other locales with
    // `$this->form->getState()`, which saves relationships (one save); 1.x
    // uses `getState(false)` and saves none. Either way this pins that the
    // real concern behaves like the hook the emulated tests stand in for.
    // Decide by the concern's own code, not a version number: a loop built on
    // `getState(false)` never saves relationships, one built on `getState()` does.
    $method = new ReflectionMethod(TranslatableEditPost::class, 'handleRecordUpdate');
    $source = implode('', array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    $concernSavesRelationships = ! str_contains($source, 'getState(false)');

    expect($saves)->toBe($concernSavesRelationships ? 1 : 0);
});

test('a new nested row is created once with the real translatable concern', function () {
    $post = makeTranslatablePost();
    $item = PostItem::create(['post_id' => $post->getKey(), 'label' => 'Item', 'position' => 1]);
    PostSubItem::create(['post_item_id' => $item->getKey(), 'label' => 'Existing']);

    $page = Livewire::test(TranslatableEditPost::class, ['record' => $post->getKey()]);
    $items = $page->get('data.items');
    $itemKey = array_key_first($items);
    $subitems = $items[$itemKey]['subitems'];
    $subitems['new-row'] = ['label' => 'Added'];

    $page->set('data.title', 'Hello!')
        ->set("data.items.{$itemKey}.subitems", $subitems)
        ->call('flushAutosave');

    expect(PostSubItem::query()->where('post_item_id', $item->getKey())->pluck('label')->sort()->values()->all())
        ->toBe(['Added', 'Existing']);
});

test('a new top-level row is created once with the real translatable concern', function () {
    $post = makeTranslatablePost();
    PostItem::create(['post_id' => $post->getKey(), 'label' => 'Existing', 'position' => 1]);

    $page = Livewire::test(TranslatableEditPost::class, ['record' => $post->getKey()]);
    $items = $page->get('data.items');
    $items['new-row'] = ['label' => 'Added', 'position' => 2, 'subitems' => []];

    $page->set('data.title', 'Hello!')->set('data.items', $items)->call('flushAutosave');

    expect(PostItem::query()->where('post_id', $post->getKey())->pluck('label')->sort()->values()->all())
        ->toBe(['Added', 'Existing']);
});

test('a nested edit on an existing row is written once with the real translatable concern', function () {
    $post = makeTranslatablePost();
    $item = PostItem::create(['post_id' => $post->getKey(), 'label' => 'Item', 'position' => 1]);
    $subitem = PostSubItem::create(['post_item_id' => $item->getKey(), 'label' => 'Original']);
    $updates = 0;
    PostSubItem::updated(function () use (&$updates): void {
        $updates++;
    });

    $page = Livewire::test(TranslatableEditPost::class, ['record' => $post->getKey()]);
    $items = $page->get('data.items');
    $itemKey = array_key_first($items);
    $subKey = array_key_first($items[$itemKey]['subitems']);

    $page->set('data.title', 'Hello!')
        ->set("data.items.{$itemKey}.subitems.{$subKey}.label", 'Edited')
        ->call('flushAutosave');

    expect($subitem->fresh()->label)->toBe('Edited')
        ->and(PostSubItem::query()->where('post_item_id', $item->getKey())->count())->toBe(1)
        ->and($updates)->toBe(1);
});

test('autosaving a translatable column writes only the active locale', function () {
    $post = makeTranslatablePost();

    Livewire::test(TranslatableEditPost::class, ['record' => $post->getKey()])
        ->assertSet('activeLocale', 'en')
        ->assertSet('data.title', 'Hello')
        ->set('data.title', 'Hello again')
        ->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');

    expect($post->fresh()->getTranslations('title'))->toBe(['en' => 'Hello again', 'es' => 'Hola']);
});

test('switching locale and autosaving keeps the other locale intact', function () {
    $post = makeTranslatablePost();

    Livewire::test(TranslatableEditPost::class, ['record' => $post->getKey()])
        ->call('setActiveLocale', 'es')
        ->assertSet('data.title', 'Hola')
        ->set('data.title', 'Hola de nuevo')
        ->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');

    expect($post->fresh()->getTranslations('title'))->toBe(['en' => 'Hello', 'es' => 'Hola de nuevo']);
});
