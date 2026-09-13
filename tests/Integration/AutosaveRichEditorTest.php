<?php

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\RichUploadEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\RichUploadPost;
use Livewire\Livewire;

beforeEach(function () {
    $migration = require __DIR__.'/../../vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub';
    $migration->up();
});

test('rich editor attachment cleanup runs during autosave and disables undo', function () {
    $post = RichUploadPost::create([
        'title' => 'Post',
        'body' => ['type' => 'doc', 'content' => []],
    ]);
    $media = $post->addMediaFromString('image')->usingFileName('image.png')->toMediaCollection('content', 'public');
    $post->update(['body' => [
        'type' => 'doc',
        'content' => [['type' => 'image', 'attrs' => ['id' => $media->uuid, 'src' => $media->getUrl(), 'alt' => null]]],
    ]]);

    $page = Livewire::test(RichUploadEditPost::class, ['record' => $post->getKey()]);
    $page->set('data.body', ['type' => 'doc', 'content' => []])->call('autosave');

    expect($post->fresh()->getMedia('content'))->toHaveCount(0)
        ->and($page->get('autosaveCanUndo'))->toBeFalse();
});
