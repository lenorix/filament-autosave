<?php

use Illuminate\Support\Facades\Event;
use Lenorix\FilamentAutosave\Events\AutosaveConflict;
use Lenorix\FilamentAutosave\Events\AutosaveFailed;
use Lenorix\FilamentAutosave\Events\AutosaveSaved;
use Lenorix\FilamentAutosave\Events\AutosaveSkipped;
use Lenorix\FilamentAutosave\Events\AutosaveUndone;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\AutosavePostForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\CreatePost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EventsFailingAfterSaveEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EventsTitleOnlyRecordForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;
use Livewire\Livewire;

test('a saved edit-page autosave dispatches AutosaveSaved with the written data', function () {
    Event::fake([AutosaveSaved::class]);
    $post = Post::create(['title' => 'Original']);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Changed')
        ->call('autosave');

    Event::assertDispatched(AutosaveSaved::class, fn (AutosaveSaved $event): bool => $event->page instanceof EditPost
        && $event->record?->is($post)
        && ($event->data['title'] ?? null) === 'Changed'
        && $event->pending === []);
});

test('a real typed listener receives AutosaveSaved without breaking the write', function () {
    $received = null;
    Event::listen(AutosaveSaved::class, function (AutosaveSaved $event) use (&$received): void {
        $received = $event;
    });
    $post = Post::create(['title' => 'Original']);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Changed')
        ->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');

    expect($received)->toBeInstanceOf(AutosaveSaved::class)
        ->and($received->record->title)->toBe('Changed')
        ->and($post->fresh()->title)->toBe('Changed');
});

test('an autosave that writes nothing because validation dropped every field dispatches AutosaveSkipped', function () {
    Event::fake([AutosaveSkipped::class, AutosaveSaved::class]);
    $post = Post::create(['title' => 'Original']);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', '')
        ->call('autosave');

    Event::assertDispatched(AutosaveSkipped::class, fn (AutosaveSkipped $event): bool => $event->reason === 'validation'
        && in_array('title', $event->pending, true)
        && array_key_exists('title', $event->errors));
    Event::assertNotDispatched(AutosaveSaved::class);
});

test('an autosave with nothing to persist dispatches AutosaveSkipped with the nothing-to-persist reason', function () {
    Event::fake([AutosaveSkipped::class]);
    $post = Post::create(['title' => 'Original']);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])->call('autosave');

    Event::assertDispatched(AutosaveSkipped::class, fn (AutosaveSkipped $event): bool => $event->reason === 'unchanged');
});

test('a failing autosave dispatches AutosaveFailed with the exception, and a real listener receives it', function () {
    $received = null;
    Event::listen(AutosaveFailed::class, function (AutosaveFailed $event) use (&$received): void {
        $received = $event;
    });
    $post = Post::create(['title' => 'Original']);

    Livewire::test(EventsFailingAfterSaveEditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Changed')
        ->call('autosave')
        ->assertDispatched('autosave-status', status: 'error');

    expect($received)->toBeInstanceOf(AutosaveFailed::class)
        ->and($received->exception)->toBeInstanceOf(RuntimeException::class)
        ->and($received->exception->getMessage())->toBe('after save failed')
        ->and($received->context)->toBe('save');
});

test('an undone edit-page autosave dispatches AutosaveUndone', function () {
    Event::fake([AutosaveUndone::class]);
    $post = Post::create(['title' => 'Original']);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Changed')
        ->call('autosave')
        ->call('undoAutosave');

    Event::assertDispatched(AutosaveUndone::class, fn (AutosaveUndone $event): bool => $event->record?->is($post) === true);
});

test('an undo cancelled by a concurrent change dispatches AutosaveConflict', function () {
    Event::fake([AutosaveConflict::class, AutosaveUndone::class]);
    $post = Post::create(['title' => 'Original']);

    $page = Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Changed')
        ->call('autosave');
    $post->update(['title' => 'Someone else']);
    $page->call('undoAutosave');

    Event::assertDispatched(AutosaveConflict::class, fn (AutosaveConflict $event): bool => $event->record?->is($post) === true);
    Event::assertNotDispatched(AutosaveUndone::class);
});

test('generic record forms dispatch AutosaveSaved and AutosaveUndone with their record', function () {
    Event::fake([AutosaveSaved::class, AutosaveUndone::class]);
    $post = Post::create(['title' => 'Original']);

    Livewire::test(EventsTitleOnlyRecordForm::class, ['record' => $post])
        ->set('data.title', 'Changed')
        ->call('autosave')
        ->call('undoAutosave');

    Event::assertDispatched(AutosaveSaved::class, fn (AutosaveSaved $event): bool => $event->record?->is($post) === true
        && ($event->data['title'] ?? null) === 'Changed');
    Event::assertDispatched(AutosaveUndone::class, fn (AutosaveUndone $event): bool => $event->page instanceof EventsTitleOnlyRecordForm && $event->record?->is($post) === true);
});

test('recordless drafts and create pages dispatch AutosaveSaved with a null record', function () {
    Event::fake([AutosaveSaved::class]);

    Livewire::test(AutosavePostForm::class)->set('data.title', 'Draft')->call('autosave');
    Livewire::test(CreatePost::class)->set('data.title', 'Draft')->call('autosave');

    Event::assertDispatched(AutosaveSaved::class, fn (AutosaveSaved $event): bool => $event->page instanceof AutosavePostForm && $event->record === null);
    Event::assertDispatched(AutosaveSaved::class, fn (AutosaveSaved $event): bool => $event->page instanceof CreatePost && $event->record === null);
});
