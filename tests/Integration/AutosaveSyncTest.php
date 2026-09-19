<?php

use Illuminate\Support\Facades\Event;
use Lenorix\FilamentAutosave\Events\AutosaveSynced;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages\FailingRefillEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages\PollingEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms\AutosaveColumnsRecordForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms\AutosavePostForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Category;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\CreatePost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\EditPost;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/** @return array<string, mixed>|null The payload of the last `synced` status event, if any. */
function lastSyncedPayload(Testable $page): ?array
{
    $found = null;

    try {
        $page->assertDispatched('autosave-status', function (string $event, array $params) use (&$found): bool {
            if (($params['status'] ?? null) === 'synced') {
                $found = $params;
            }

            return true;
        });
    } catch (Throwable) {
        // No status event dispatched at all.
    }

    return $found;
}

test('a poll pulls another editor\'s change into a clean field on an edit page', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = Livewire::test(EditPost::class, ['record' => $post->getKey()]);

    Post::query()->whereKey($post->getKey())->update(['slug' => 'changed-elsewhere']);

    $page->call('syncAutosave');

    expect($page->get('data.slug'))->toBe('changed-elsewhere')
        ->and(lastSyncedPayload($page))->toMatchArray(['refreshed' => ['slug' => 'changed-elsewhere'], 'stale' => []]);
});

test('a poll never overwrites a locally dirty field and reports it as stale instead', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Typing locally');

    Post::query()->whereKey($post->getKey())->update(['title' => 'Changed elsewhere', 'slug' => 'also-changed']);

    $page->call('syncAutosave');

    expect($page->get('data.title'))->toBe('Typing locally')
        ->and($page->get('data.slug'))->toBe('also-changed')
        ->and(lastSyncedPayload($page))->toMatchArray(['refreshed' => ['slug' => 'also-changed'], 'stale' => ['title']]);
});

test('a poll with no remote change dispatches nothing', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = Livewire::test(EditPost::class, ['record' => $post->getKey()]);

    $page->call('syncAutosave')->assertNotDispatched('autosave-status');
});

test('a poll does not report the page\'s own last save as a remote change', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.slug', 'mine')
        ->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');

    $page->call('syncAutosave')->assertNotDispatched('autosave-status', status: 'synced');
});

test('a poll acknowledges the refreshed value so a later autosave does not write it back', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = Livewire::test(EditPost::class, ['record' => $post->getKey()]);

    Post::query()->whereKey($post->getKey())->update(['slug' => 'changed-elsewhere']);
    $page->call('syncAutosave');

    Post::query()->whereKey($post->getKey())->update(['slug' => 'changed-again']);
    $page->set('data.title', 'Local edit')->call('autosave');

    expect($post->fresh()->slug)->toBe('changed-again')
        ->and($post->fresh()->title)->toBe('Local edit');
});

test('a poll fires the package event with the refreshed and stale paths', function () {
    Event::fake([AutosaveSynced::class]);
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = Livewire::test(EditPost::class, ['record' => $post->getKey()])->set('data.title', 'dirty');

    Post::query()->whereKey($post->getKey())->update(['title' => 'remote', 'slug' => 'remote-slug']);
    $page->call('syncAutosave');

    Event::assertDispatched(AutosaveSynced::class, fn (AutosaveSynced $e): bool => $e->record?->is($post)
        && $e->refreshed === ['slug' => 'remote-slug']
        && $e->stale === ['title']);
});

test('the poll respects excluded fields', function () {
    config(['filament-autosave.except' => ['slug']]);
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = Livewire::test(EditPost::class, ['record' => $post->getKey()]);

    Post::query()->whereKey($post->getKey())->update(['slug' => 'changed-elsewhere']);
    $page->call('syncAutosave')->assertNotDispatched('autosave-status', status: 'synced');

    expect($page->get('data.slug'))->toBe('original');
});

test('a poll on a record-backed generic form behaves like the edit page', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = Livewire::test(AutosaveColumnsRecordForm::class, ['record' => $post])
        ->set('data.title', 'Typing locally');

    Post::query()->whereKey($post->getKey())->update(['title' => 'Changed elsewhere', 'slug' => 'also-changed']);
    $page->call('syncAutosave');

    expect($page->get('data.title'))->toBe('Typing locally')
        ->and($page->get('data.slug'))->toBe('also-changed')
        ->and(lastSyncedPayload($page))->toMatchArray(['refreshed' => ['slug' => 'also-changed'], 'stale' => ['title']]);
});

test('a poll is a no-op on drafts and create pages', function () {
    Livewire::test(AutosavePostForm::class)->set('data.title', 'Draft')
        ->call('syncAutosave')->assertNotDispatched('autosave-status')
        ->assertSet('autosavePollMs', 0);

    Livewire::test(CreatePost::class)->set('data.title', 'Draft')
        ->call('syncAutosave')->assertNotDispatched('autosave-status')
        ->assertSet('autosavePollMs', 0);
});

test('the poll interval follows config, plugin and page precedence and 0 disables it', function () {
    $post = Post::create(['title' => 'Original']);

    config(['filament-autosave.poll_interval' => 7000]);
    Livewire::test(EditPost::class, ['record' => $post->getKey()])->assertSet('autosavePollMs', 7000);
    Livewire::test(PollingEditPost::class, ['record' => $post->getKey()])->assertSet('autosavePollMs', 1234);

    config(['filament-autosave.poll_interval' => 0]);
    Livewire::test(EditPost::class, ['record' => $post->getKey()])->assertSet('autosavePollMs', 0);
});

test('polling is off when the refresh it relies on is off', function (string $key) {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    config(["filament-autosave.{$key}" => false]);
    $page = Livewire::test(EditPost::class, ['record' => $post->getKey()])->assertSet('autosavePollMs', 0);

    Post::query()->whereKey($post->getKey())->update(['slug' => 'changed-elsewhere']);

    // Even when called directly, the poll must not refill: the next
    // save would otherwise write every column from this tab's state.
    $page->call('syncAutosave')->assertNotDispatched('autosave-status');

    expect($page->get('data.slug'))->toBe('original');
})->with(['refresh_unchanged_fields', 'dirty_only']);

test('a remote change is not forgotten when refilling it fails once', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = Livewire::test(FailingRefillEditPost::class, ['record' => $post->getKey()]);

    Post::query()->whereKey($post->getKey())->update(['slug' => 'changed-elsewhere']);

    $page->set('autosaveFailNextRefill', true)->call('syncAutosave')
        ->assertDispatched('autosave-status', status: 'error');

    expect($page->get('data.slug'))->toBe('original');

    $page->call('syncAutosave');

    expect($page->get('data.slug'))->toBe('changed-elsewhere');
});

test('a blank required field the user is still editing is reported stale, never refilled', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', '')
        ->call('autosave');

    Post::query()->whereKey($post->getKey())->update(['title' => 'Changed elsewhere', 'slug' => 'also-changed']);
    $page->call('syncAutosave');

    expect($page->get('data.title'))->toBe('')
        ->and($page->get('data.slug'))->toBe('also-changed')
        ->and(lastSyncedPayload($page))->toMatchArray(['refreshed' => ['slug' => 'also-changed'], 'stale' => ['title']]);
});

test('a poll only refreshes declared form fields, not every record column', function () {
    $category = Category::create(['name' => 'News']);
    $other = Category::create(['name' => 'Guides']);
    $post = Post::create(['title' => 'Original', 'slug' => 'original', 'category_id' => $category->getKey()]);
    $page = Livewire::test(AutosaveColumnsRecordForm::class, ['record' => $post]);
    $before = $page->get('data');

    Post::query()->whereKey($post->getKey())->update(['slug' => 'slug-by-b', 'category_id' => $other->getKey()]);

    $page->call('syncAutosave');

    $after = $page->get('data');
    unset($before['slug'], $after['slug']);

    expect($page->get('data.slug'))->toBe('slug-by-b')
        ->and(lastSyncedPayload($page))->toMatchArray(['refreshed' => ['slug' => 'slug-by-b'], 'stale' => []])
        ->and($after)->toBe($before);
});
