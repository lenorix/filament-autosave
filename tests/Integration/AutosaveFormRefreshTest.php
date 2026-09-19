<?php

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms\AutosaveColumnsRecordForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;
use Livewire\Livewire;

beforeEach(function () {
    config(['filament-autosave.dirty_only' => true, 'filament-autosave.refresh_unchanged_fields' => true]);
});

test('a generic record form refreshes untouched columns another editor changed after its own save', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    $a = Livewire::test(AutosaveColumnsRecordForm::class, ['record' => $post]);
    $b = Livewire::test(AutosaveColumnsRecordForm::class, ['record' => $post]);

    $a->set('data.title', 'Title by A');
    $b->set('data.slug', 'slug-by-b')->call('autosave');

    $a->call('autosave')
        ->assertDispatched('autosave-status', fn (string $event, array $params): bool => $params['status'] === 'saved'
            && ($params['refreshed']['slug'] ?? null) === 'slug-by-b');

    expect($a->get('data.slug'))->toBe('slug-by-b')
        ->and($a->get('data.title'))->toBe('Title by A')
        ->and($post->fresh()->only(['title', 'slug']))->toBe(['title' => 'Title by A', 'slug' => 'slug-by-b']);
});

test('a dirty column is never overwritten by a refresh', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    $a = Livewire::test(AutosaveColumnsRecordForm::class, ['record' => $post]);
    $post->update(['title' => 'Changed elsewhere']);

    $a->set('data.title', 'Mine')->call('autosave');

    expect($a->get('data.title'))->toBe('Mine')
        ->and($post->fresh()->title)->toBe('Mine');
});

test('the refresh can be disabled by configuration', function () {
    config(['filament-autosave.refresh_unchanged_fields' => false]);
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    $a = Livewire::test(AutosaveColumnsRecordForm::class, ['record' => $post]);
    $post->update(['slug' => 'slug-elsewhere']);

    $a->set('data.title', 'Mine')->call('autosave')
        ->assertDispatched('autosave-status', fn (string $event, array $params): bool => $params['status'] === 'saved'
            && ($params['refreshed'] ?? []) === []);

    expect($a->get('data.slug'))->toBe('original');
});

test('a refreshed column is acknowledged so the next cycle does not rewrite it', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    $a = Livewire::test(AutosaveColumnsRecordForm::class, ['record' => $post]);
    $post->update(['slug' => 'slug-elsewhere']);
    $a->set('data.title', 'Mine')->call('autosave');
    expect($a->get('data.slug'))->toBe('slug-elsewhere');

    $post->update(['slug' => 'slug-newer']);
    $a->set('data.title', 'Mine again')->call('autosave');

    expect($post->fresh()->slug)->toBe('slug-newer')
        ->and($a->get('data.slug'))->toBe('slug-newer');
});
