<?php

use Filament\Resources\Events\RecordSaved;
use Filament\Resources\Events\RecordUpdated;
use Illuminate\Support\Facades\Event;
use Lenorix\FilamentAutosave\Events\AutosaveFailed;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms\AutosaveCommentsRelationManager;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms\AutosaveUploadRecordForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Comment;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\EditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\EditPostSettingsPage;
use Livewire\Livewire;

test('a real listener typed against RecordUpdated receives the actual event object', function () {
    $post = Post::create(['title' => 'Original']);
    $received = null;

    Event::listen(RecordUpdated::class, function (RecordUpdated $event) use (&$received) {
        $received = $event;
    });

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Changed')
        ->call('autosave');

    expect($received)->toBeInstanceOf(RecordUpdated::class)
        ->and($received->getRecord()->is($post->fresh()))->toBeTrue()
        ->and($received->getData())->toHaveKey('title', 'Changed');
})->skip(fn (): bool => ! class_exists(RecordUpdated::class), 'Filament\\Resources\\Events does not exist on this Filament version (4.0.x)');

test('a real listener typed against RecordSaved receives the actual event object', function () {
    $post = Post::create(['title' => 'Original']);
    $received = null;

    Event::listen(RecordSaved::class, function (RecordSaved $event) use (&$received) {
        $received = $event;
    });

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Changed')
        ->call('autosave');

    expect($received)->toBeInstanceOf(RecordSaved::class)
        ->and($received->getPage())->toBeInstanceOf(EditPost::class);
})->skip(fn (): bool => ! class_exists(RecordUpdated::class), 'Filament\\Resources\\Events does not exist on this Filament version (4.0.x)');

test('a typed RecordUpdated listener does not crash a generic record form autosave', function () {
    Event::listen(RecordUpdated::class, function (RecordUpdated $event) {
        // Exists purely so autosave has to survive a real, typed listener.
    });

    $post = Post::create(['title' => 'Original']);

    Livewire::test(AutosaveUploadRecordForm::class, ['record' => $post])
        ->set('data.title', 'Changed')
        ->call('autosave')->assertDispatched('autosave-status', status: 'saved');

    expect($post->fresh()->title)->toBe('Changed');
})->skip(fn (): bool => ! class_exists(RecordUpdated::class), 'Filament\\Resources\\Events does not exist on this Filament version (4.0.x)');

test('a generic form hosted on a resource page dispatches real RecordUpdated and RecordSaved objects', function () {
    $post = Post::create(['title' => 'Original']);
    $received = [];

    Event::listen(RecordUpdated::class, function (RecordUpdated $event) use (&$received) {
        $received['updated'] = $event;
    });
    Event::listen(RecordSaved::class, function (RecordSaved $event) use (&$received) {
        $received['saved'] = $event;
    });

    Livewire::test(EditPostSettingsPage::class, ['record' => $post])
        ->set('data.title', 'Changed')
        ->call('autosave')->assertDispatched('autosave-status', status: 'saved');

    expect($received['updated'] ?? null)->toBeInstanceOf(RecordUpdated::class)
        ->and($received['updated']->getRecord()->is($post->fresh()))->toBeTrue()
        ->and($received['updated']->getData())->toHaveKey('title', 'Changed')
        ->and($received['saved'] ?? null)->toBeInstanceOf(RecordSaved::class)
        ->and($received['saved']->getPage())->toBeInstanceOf(EditPostSettingsPage::class);
})->skip(fn (): bool => ! class_exists(RecordUpdated::class), 'Filament\\Resources\\Events does not exist on this Filament version (4.0.x)');

test('a relation manager action autosave dispatches no Filament record events and swallows no exception', function () {
    $post = Post::create(['title' => 'Post']);
    $comment = Comment::create([
        'body' => 'Original comment',
        'commentable_type' => $post->getMorphClass(),
        'commentable_id' => $post->getKey(),
    ]);
    $dispatched = 0;
    $failed = 0;

    Event::listen(RecordUpdated::class, function (RecordUpdated $event) use (&$dispatched) {
        $dispatched++;
    });
    Event::listen(RecordSaved::class, function (RecordSaved $event) use (&$dispatched) {
        $dispatched++;
    });
    Event::listen(AutosaveFailed::class, function () use (&$failed) {
        $failed++;
    });

    $page = Livewire::test(AutosaveCommentsRelationManager::class, [
        'ownerRecord' => $post,
        'pageClass' => 'Lenorix\\FilamentAutosave\\Tests\\Fixtures\\Integration\\Resources\\Relationship\\RelationshipEditPost',
    ]);
    $instance = $page->instance();
    $instance->mountTableAction('edit', (string) $comment->getKey());
    $instance->fillMountedEdit(['body' => 'Changed']);
    $instance->autosave();

    expect($comment->fresh()->body)->toBe('Changed')
        ->and($dispatched)->toBe(0)
        ->and($failed)->toBe(0);
})->skip(fn (): bool => ! class_exists(RecordUpdated::class), 'Filament\\Resources\\Events does not exist on this Filament version (4.0.x)');
