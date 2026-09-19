<?php

use Lenorix\FilamentAutosave\AutosaveManager;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\CreatePost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\ValidatedEditPost;
use Livewire\Livewire;

test('panel edit pages commit autosaved changes to the database', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->assertSet('autosaveEnabled', true)
        ->set('data.title', 'Autosaved')
        ->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');

    expect($post->refresh()->title)->toBe('Autosaved');
});

test('panel edit pages keep password fields out of autosave', function () {
    $post = Post::create(['title' => 'Original']);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Autosaved')
        ->set('data.vault_key', 'plain-secret')
        ->call('autosave');

    expect($post->refresh()->title)->toBe('Autosaved');
    expect($post->getAttributes())->not->toHaveKey('vault_key');
});

test('panel edit pages store nested group data as one whole column value', function () {
    $post = Post::create(['title' => 'Original', 'settings' => ['theme' => 'light', 'mode' => 'fast']]);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.settings.theme', 'dark')
        ->call('autosave');

    expect($post->refresh()->settings)->toBe(['theme' => 'dark', 'mode' => 'fast']);
});

test('panel edit pages keep stored groups when a nested select option is invalid', function () {
    $post = Post::create(['title' => 'Original', 'settings' => ['theme' => 'light', 'mode' => 'fast']]);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.settings.mode', 'HACKED')
        ->call('autosave');

    expect($post->refresh()->settings)->toBe(['theme' => 'light', 'mode' => 'fast']);
});

test('panel edit pages write qualifying fields while a required one sits empty', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', '')
        ->set('data.slug', 'changed')
        ->call('autosave');

    $post->refresh();

    expect($post->title)->toBe('Original');
    expect($post->slug)->toBe('changed');
});

test('panel autosave validation is added to Filament native errors with the field label', function () {
    $post = Post::create(['title' => 'Original']);

    $component = Livewire::test(ValidatedEditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Too long')
        ->call('autosave')
        ->assertHasErrors(['title'])
        ->assertSet('autosavePendingFields', ['title'])
        ->assertDispatched('autosave-status', status: 'validation');

    expect($component->errors()->first('title'))->toContain('Title');
});

test('a later valid autosave clears its previous native field errors', function () {
    $post = Post::create(['title' => 'Original']);
    $component = Livewire::test(ValidatedEditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Too long')
        ->call('autosave')
        ->assertHasErrors(['title']);

    $component
        ->set('data.title', 'Ok')
        ->call('autosave')
        ->assertHasNoErrors();
});

test('panel edit pages bring back prior values after an undo', function () {
    $post = Post::create(['title' => 'Original']);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Autosaved')
        ->call('autosave')
        ->assertSet('autosaveCanUndo', true)
        ->call('undoAutosave')
        ->assertDispatched('autosave-status', status: 'undone')
        ->assertSet('autosaveCanUndo', false);

    expect($post->refresh()->title)->toBe('Original');
});

test('panel undo preserves a newer concurrent update to the same column', function () {
    $post = Post::create(['title' => 'Original']);
    $component = Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Autosaved')
        ->call('autosave')
        ->assertSet('autosaveCanUndo', true);

    $post->refresh()->update(['title' => 'Changed elsewhere']);

    $component
        ->call('undoAutosave')
        ->assertDispatched('autosave-status', status: 'conflict')
        ->assertSet('autosaveCanUndo', false);

    expect($post->refresh()->title)->toBe('Changed elsewhere');
});

test('panel create pages cache drafts and recover them on a later visit', function () {
    Livewire::test(CreatePost::class)
        ->set('data.title', 'Drafted')
        ->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');

    expect(Post::count())->toBe(0);

    $draft = AutosaveManager::restoreDraft(AutosaveManager::cacheKey(CreatePost::class));
    expect($draft)->toHaveKey('title', 'Drafted');

    Livewire::test(CreatePost::class)
        ->assertSet('autosaveHasDraft', true)
        ->call('restoreDraft')
        ->assertSet('data.title', 'Drafted')
        ->assertSet('autosaveHasDraft', false);
});

test('panel create pages purge drafts once the record is created', function () {
    Livewire::test(CreatePost::class)
        ->set('data.title', 'Drafted')
        ->call('autosave')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Post::where('title', 'Drafted')->exists())->toBeTrue();
    expect(AutosaveManager::restoreDraft(AutosaveManager::cacheKey(CreatePost::class)))->toBeNull();
});

test('panel create pages keep drafts when validation blocks creation', function () {
    Livewire::test(CreatePost::class)
        ->set('data.slug', 'no-title-yet')
        ->call('autosave')
        ->call('create')
        ->assertHasFormErrors(['title']);

    expect(Post::count())->toBe(0);
    expect(AutosaveManager::restoreDraft(AutosaveManager::cacheKey(CreatePost::class)))
        ->toHaveKey('slug', 'no-title-yet');
});
