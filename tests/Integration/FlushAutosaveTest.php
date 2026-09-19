<?php

use Illuminate\Validation\ValidationException;
use Lenorix\FilamentAutosave\AutosaveManager;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\AutosavePostForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\AutosaveUploadRecordForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\CreatePost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\GuardedFlushEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\ReentrantFlushEditPost;
use Livewire\Livewire;

test('flushAutosave writes dirty fields synchronously and reports whether it wrote', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Flushed');

    $page->call('flushAutosave')
        ->assertReturned(true)
        ->assertDispatched('autosave-status', status: 'saved');

    expect($post->refresh()->title)->toBe('Flushed')
        ->and($page->instance()->flushAutosave())->toBeFalse();
});

test('flushAutosave throws a validation exception and writes nothing', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', '')
        ->set('data.slug', 'changed');

    try {
        $page->instance()->flushAutosave();
        $this->fail('Expected a ValidationException');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('data.title');
    }

    expect($post->refresh()->slug)->toBe('original');

    $page->set('data.title', 'Fixed');
    expect($page->instance()->flushAutosave())->toBeTrue()
        ->and($post->refresh()->only(['title', 'slug']))->toBe(['title' => 'Fixed', 'slug' => 'changed']);
});

test('flushAutosave propagates domain guard exceptions and leaves the record unchanged', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = Livewire::test(GuardedFlushEditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'forbidden')
        ->set('data.slug', 'changed');

    expect(fn () => $page->instance()->flushAutosave())->toThrow(DomainException::class, 'title is reserved')
        ->and($post->refresh()->only(['title', 'slug']))->toBe(['title' => 'Original', 'slug' => 'original']);

    $page->set('data.title', 'allowed');
    expect($page->instance()->flushAutosave())->toBeTrue()
        ->and($post->refresh()->title)->toBe('allowed');
});

test('autosave keeps swallowing the same failures the indicator reports', function () {
    $post = Post::create(['title' => 'Original']);

    Livewire::test(GuardedFlushEditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'forbidden')
        ->call('autosave')
        ->assertDispatched('autosave-status', status: 'error');

    expect($post->refresh()->title)->toBe('Original');
});

test('flushAutosave works for record-backed generic forms', function () {
    $post = Post::create(['title' => 'Post']);
    $page = Livewire::test(AutosaveUploadRecordForm::class, ['record' => $post])
        ->set('data.title', 'Flushed');

    expect($page->instance()->flushAutosave())->toBeTrue()
        ->and($post->refresh()->title)->toBe('Flushed');
});

test('flushAutosave stores a draft for recordless generic forms', function () {
    $page = Livewire::test(AutosavePostForm::class)->set('data.title', 'Draft');

    expect($page->instance()->flushAutosave())->toBeTrue();
    $page->assertSet('autosaveHasDraft', true);
});

test('flushAutosave stores a draft for create pages', function () {
    $page = Livewire::test(CreatePost::class)->set('data.title', 'Draft');

    expect($page->instance()->flushAutosave())->toBeTrue()
        ->and($page->instance()->flushAutosave())->toBeFalse()
        ->and(AutosaveManager::restoreDraft(AutosaveManager::cacheKey(CreatePost::class)))->toHaveKey('title', 'Draft');
});

test('flushAutosave refuses to run inside an autosave cycle instead of silently doing nothing', function () {
    $post = Post::create(['title' => 'Original']);
    $page = Livewire::test(ReentrantFlushEditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Changed');

    expect(fn () => $page->instance()->flushAutosave())->toThrow(LogicException::class);
});
