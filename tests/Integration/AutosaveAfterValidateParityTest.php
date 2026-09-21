<?php

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages\ProbingAfterValidateEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;
use Livewire\Livewire;

test('afterValidate is not called when any field fails validation, matching Filament', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    Livewire::test(ProbingAfterValidateEditPost::class, ['record' => $post->getKey()])
        ->set('data.title', '')
        ->set('data.slug', 'changed')
        ->call('autosave')
        ->assertSet('afterValidateCalls', 0)
        ->assertDispatched('autosave-status', status: 'validation');

    expect($post->refresh()->title)->toBe('Original')
        ->and($post->refresh()->slug)->toBe('changed');
});
