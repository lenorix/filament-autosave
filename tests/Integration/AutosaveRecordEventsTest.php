<?php

use Filament\Resources\Events\RecordSaved;
use Filament\Resources\Events\RecordUpdated;
use Illuminate\Support\Facades\Event;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\AutosaveUploadRecordForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;
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
