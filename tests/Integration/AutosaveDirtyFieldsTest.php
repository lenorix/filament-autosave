<?php

use Filament\Facades\Filament;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\AfterChangedEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\HaltedSaveEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\ServerChangedEditPost;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

test('dirty hashes survive requests and preserve another editors untouched columns', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = Livewire::test(EditPost::class, ['record' => $post->getKey()]);
    $post->update(['slug' => 'another-editor']);

    $page->set('data.title', 'First')->call('autosave');
    expect($post->refresh()->slug)->toBe('another-editor');

    $post->update(['slug' => 'another-edit']);
    $page->set('data.title', 'Second')->call('autosave');
    expect($post->refresh()->slug)->toBe('another-edit');
});

test('dirty-only autosave refreshes clean fields changed by another editor', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = Livewire::test(EditPost::class, ['record' => $post->getKey()]);
    $post->update(['slug' => 'another-editor']);

    $page->set('data.title', 'First')->call('autosave')
        ->assertSet('data.slug', 'another-editor')
        ->assertDispatched('autosave-status', function ($name, $params) {
            return $params['status'] === 'saved'
                && ($params['refreshed']['slug'] ?? null) === 'another-editor';
        });
});

test('refreshing clean fields does not replace a local dirty field', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = Livewire::test(EditPost::class, ['record' => $post->getKey()]);
    $post->update(['title' => 'another-editor']);

    $page->set('data.title', 'local-edit')->call('autosave')
        ->assertSet('data.title', 'local-edit');
});

test('clean-field refresh can be disabled', function () {
    config(['filament-autosave.refresh_unchanged_fields' => false]);
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = Livewire::test(EditPost::class, ['record' => $post->getKey()]);
    $post->update(['slug' => 'another-editor']);

    $page->set('data.title', 'First')->call('autosave')
        ->assertSet('data.slug', 'original');
});

test('two edit sessions can autosave different columns without colliding', function () {
    config(['filament-autosave.dirty_only' => true]);

    $post = Post::create([
        'title' => 'Original title',
        'slug' => 'original-slug',
    ]);

    // Both sessions load the same original snapshot before either one saves.
    $titleEditor = Livewire::test(EditPost::class, ['record' => $post->getKey()]);
    $slugEditor = Livewire::test(EditPost::class, ['record' => $post->getKey()]);

    $titleEditor->set('data.title', 'Edited title');
    $slugEditor->set('data.slug', 'edited-slug');

    $titleEditor->call('autosave')->assertDispatched('autosave-status', status: 'saved');
    $slugEditor->call('autosave')->assertDispatched('autosave-status', status: 'saved');

    expect($post->refresh()->title)->toBe('Edited title')
        ->and($post->slug)->toBe('edited-slug');
});

test('turning off dirty only keeps full column writes across requests', function () {
    config(['filament-autosave.dirty_only' => false]);
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = Livewire::test(EditPost::class, ['record' => $post->getKey()]);
    $post->update(['slug' => 'another-editor']);
    $page->set('data.title', 'Changed')->call('autosave');
    expect($post->refresh()->slug)->toBe('original');
});

test('the baseline contains hashes rather than form values and is locked', function () {
    $post = Post::create(['title' => 'Sensitive original']);
    $page = Livewire::test(EditPost::class, ['record' => $post->getKey()]);
    $hashes = $page->get('autosaveFieldHashes');
    expect($hashes)->toBeArray()->toHaveKey('title');
    expect($hashes['title'])->toMatch('/^[a-f0-9]{32}$/')->not->toBe($post->title);
    $page->set('autosaveFieldHashes.title', 'forged');
})->throws(CannotUpdateLockedPropertyException::class);

test('a skipped required field remains pending after a sibling is saved', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = Livewire::test(EditPost::class, ['record' => $post->getKey()]);
    $hashes = $page->get('autosaveFieldHashes');
    $page->set('data.title', '')->set('data.slug', 'changed')->call('autosave');
    expect($page->get('autosaveFieldHashes.title'))->toBe($hashes['title']);
    $page->set('data.title', 'Valid')->call('autosave');
    expect($post->refresh()->title)->toBe('Valid');
});

test('manual save resets hashes so a subsequent revert is saved', function () {
    $post = Post::create(['title' => 'Original']);
    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Manual')->call('save', false, false)
        ->set('data.title', 'Original')->call('autosave');
    expect($post->refresh()->title)->toBe('Original');
});

test('a halted beforeSave hook prevents autosave without resetting hashes', function () {
    $post = Post::create(['title' => 'Original']);
    $page = Livewire::test(HaltedSaveEditPost::class, ['record' => $post->getKey()]);
    $hashes = $page->get('autosaveFieldHashes');
    $page->set('data.title', 'Changed')->call('save', false, false);
    expect($page->get('autosaveFieldHashes'))->toBe($hashes);
    $page->call('autosave');
    expect($post->refresh()->title)->toBe('Original');
});

test('server actions are detected without a field updated hook', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = Livewire::test(ServerChangedEditPost::class, ['record' => $post->getKey()]);
    $post->update(['title' => 'Another editor']);
    $page->call('changeSlugOnServer')->call('autosave');
    expect($post->refresh()->title)->toBe('Another editor')
        ->and($post->slug)->toBe('server-change');
});

test('a dirty-only save rebaselines unsaved alerts when unchanged siblings already match', function () {
    Filament::getCurrentPanel()->unsavedChangesAlerts();
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = Livewire::test(EditPost::class, ['record' => $post->getKey()]);
    $page->set('data.title', 'Changed')->call('autosave');
    $actual = $page->get('savedDataHash');
    expect($actual)->toBeString()->not->toBeEmpty();
    $page->call('save', false, false);
    expect($actual)->toBe($page->get('savedDataHash'));
});

test('server actions update the observed hash without an updated hook', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = Livewire::test(ServerChangedEditPost::class, ['record' => $post->getKey()]);
    $before = $page->get('autosaveObservedHash');
    expect($before)->toBeString()->not->toBeEmpty();
    $page->call('changeSlugOnServer');
    $after = $page->get('autosaveObservedHash');
    expect($after)->not->toBe($before);
    $page->call('autosave')->call('$refresh');
    expect($page->get('autosaveObservedHash'))->toBe($after);
});

test('changes made after the write are not acknowledged as saved', function () {
    $post = Post::create(['title' => 'Original']);
    Livewire::test(AfterChangedEditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'First')->call('autosave')
        ->assertSet('data.title', 'Second')->call('autosave');
    expect($post->refresh()->title)->toBe('Second');
});
