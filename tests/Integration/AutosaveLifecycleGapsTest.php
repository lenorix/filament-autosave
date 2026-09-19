<?php

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms\HookOrderRecordForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms\OwnFormActionsComponent;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\EditPost;
use Livewire\Livewire;

test('generic forms run beforeSave before the mutator, like Filament', function () {
    $post = Post::create(['title' => 'Post']);

    $page = Livewire::test(HookOrderRecordForm::class, ['record' => $post])
        ->set('data.title', 'Changed')
        ->call('autosave')->assertDispatched('autosave-status', status: 'saved');

    expect($page->get('calls'))->toBe(['beforeSave:Changed', 'mutate'])
        ->and($post->fresh()->title)->toBe('Changed');
});

test('a component with actions support falls back to its own form when no action is mounted', function () {
    Livewire::test(OwnFormActionsComponent::class)
        ->set('data.title', 'Draft')
        ->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved')
        ->assertSet('autosaveHasDraft', true);
});

test('a blank required field is reported as pending even without a validation message', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', '')
        ->set('data.slug', 'changed')
        ->call('autosave')
        ->assertDispatched('autosave-status', fn (string $event, array $params): bool => in_array('title', $params['pending'] ?? [], true));
});
