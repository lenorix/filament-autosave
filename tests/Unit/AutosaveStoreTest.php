<?php

use Illuminate\Support\Facades\Cache;
use Lenorix\FilamentAutosave\AutosaveManager;
use Lenorix\FilamentAutosave\AutosaveStore;

beforeEach(function () {
    Cache::flush();
});

test('snapshot hash is stable and content sensitive', function () {
    $data = ['title' => 'x', 'count' => 1];

    expect((new AutosaveStore)->snapshotHash($data))
        ->toBe((new AutosaveStore)->snapshotHash($data))
        ->not->toBe((new AutosaveStore)->snapshotHash(['title' => 'x', 'count' => 2]));
});

test('excludeFields strips the named fields', function () {
    $store = new AutosaveStore;

    expect($store->excludeFields(['title' => 'x', 'secret' => 'y'], ['secret']))
        ->toBe(['title' => 'x']);
});

test('cacheKey is scoped per owner and tenant', function () {
    fakeFilamentPanel(guard: 'web', id: 9);

    $store = new AutosaveStore;

    expect($store->cacheKey('Some\Page'))
        ->toBe('filament-autosave:web:9:Some\Page');
});

test('undo cache key is scoped per owner and record', function () {
    fakeFilamentPanel(guard: 'web', id: 9);

    $store = new AutosaveStore;

    expect($store->undoCacheKey('Some\Page', 3))
        ->toBe('filament-autosave:undo:web:9:Some\Page:3')
        ->and($store->undoCacheKey('Some\Page', null))
        ->toBe('filament-autosave:undo:web:9:Some\Page:default');
});

test('draft round trip stores, restores and clears the cache entry', function () {
    fakeFilamentPanel(guard: 'web', id: 9);

    $store = new AutosaveStore;
    $key = $store->cacheKey('Some\Page');

    $store->storeDraft($key, ['title' => 'draft'], 48);
    expect($store->restoreDraft($key))->toBe(['title' => 'draft']);

    $store->clearDraft($key);
    expect($store->restoreDraft($key))->toBeNull();
});

test('manager facade keeps delegating to a fresh store', function () {
    $data = ['title' => 'x', 'count' => 1];

    expect(AutosaveManager::snapshotHash($data))
        ->toBe((new AutosaveStore)->snapshotHash($data));
});

test('the store resolves as a singleton so swaps apply everywhere', function () {
    expect(app(AutosaveStore::class))->toBe(app(AutosaveStore::class));
});
