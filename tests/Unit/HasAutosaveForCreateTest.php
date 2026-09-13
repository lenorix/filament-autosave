<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Lenorix\FilamentAutosave\AutosaveManager;
use Lenorix\FilamentAutosave\Tests\Fixtures\AutosaveCreateFormComponent;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

beforeEach(function () {
    Cache::flush();
});

test('create autosave parks changed form data in a cached draft', function () {
    $page = makeCreatePage(['title' => 'Original', 'body' => 'Content']);
    $page->mountHasAutosaveForCreate();

    $page->form->setState(['title' => 'Updated', 'body' => 'Content']);
    $page->autosave();

    $key = AutosaveManager::cacheKey(get_class($page));
    expect(Cache::get($key))->toBe(['title' => 'Updated', 'body' => 'Content']);
});

test('disabled create autosave stores no draft', function () {
    $page = makeCreatePage(['title' => 'Any']);
    $page->autosaveEnabled = false;

    $page->form->setState(['title' => 'Changed']);
    $page->autosave();

    $key = AutosaveManager::cacheKey(get_class($page));
    expect(Cache::get($key))->toBeNull();
});

test('create autosave reports idle when nothing changed, keeping the cache empty', function () {
    $page = makeCreatePage(['title' => 'Same']);
    $page->mountHasAutosaveForCreate();

    $page->autosave();

    $key = AutosaveManager::cacheKey(get_class($page));
    expect(Cache::get($key))->toBeNull();
    expect($page->dispatched)->toHaveCount(1);
    expect($page->dispatched[0]['params'])->toHaveKey('status', 'idle');
});

test('create autosave keeps excluded fields out of drafts', function () {
    $page = makeCreatePage(['title' => 'A', 'secret_note' => 'secret']);
    $page->exceptFields = ['secret_note'];
    $page->mountHasAutosaveForCreate();

    $page->form->setState(['title' => 'B', 'secret_note' => 'newsecret']);
    $page->autosave();

    $key = AutosaveManager::cacheKey(get_class($page));
    $cached = Cache::get($key);

    expect($cached)->toHaveKey('title', 'B');
    expect($cached)->not->toHaveKey('secret_note');
});

test('create autosave preserves explicit blank values in drafts', function () {
    $page = makeCreatePage(['title' => 'A']);
    $page->mountHasAutosaveForCreate();

    $page->form->setState(['title' => 'B', 'note' => '', 'tags' => []]);
    $page->autosave();

    $key = AutosaveManager::cacheKey(get_class($page));
    expect(Cache::get($key))->toBe(['title' => 'B', 'note' => '', 'tags' => []]);
});

test('mounting flags a cached draft as available', function () {
    $page = makeCreatePage();
    $key = AutosaveManager::cacheKey(get_class($page));
    Cache::put($key, ['title' => 'Saved Draft'], 3600);

    $page->mountHasAutosaveForCreate();

    expect($page->autosaveHasDraft)->toBeTrue();
});

test('mounting leaves draft availability off when the cache holds nothing', function () {
    $page = makeCreatePage();

    $page->mountHasAutosaveForCreate();

    expect($page->autosaveHasDraft)->toBeFalse();
});

test('restoring a draft pours it into the form and clears the availability flag', function () {
    $page = makeCreatePage(['title' => '']);
    $key = AutosaveManager::cacheKey(get_class($page));
    Cache::put($key, ['title' => 'Draft Title'], 3600);
    $page->autosaveHasDraft = true;

    $page->restoreDraft();

    expect($page->form->getRawState())->toBe(['title' => 'Draft Title']);
    expect($page->autosaveHasDraft)->toBeFalse();
});

test("restoring a draft that isn't there reports idle", function () {
    $page = makeCreatePage(['title' => 'Empty']);

    $page->restoreDraft();

    expect($page->dispatched)->toHaveCount(1);
    expect($page->dispatched[0]['params'])->toHaveKey('status', 'idle');
});

test('restoring a draft screens cached data through the exclusion list', function () {
    $page = makeCreatePage();
    $page->exceptFields = ['secret_note'];
    $key = AutosaveManager::cacheKey(get_class($page));
    Cache::put($key, ['title' => 'Hello', 'secret_note' => 'leaked'], 3600);

    $page->restoreDraft();

    expect($page->form->getRawState())->toBe(['title' => 'Hello']);
});

test('discarding a draft demands page access', function () {
    $page = new class extends AutosaveCreateFormComponent
    {
        public function authorizeAccess(): void
        {
            throw new RuntimeException('denied');
        }
    };
    $page->mount();

    $key = (fn () => $this->getAutosaveCacheKey())->call($page);
    AutosaveManager::storeDraft($key, ['title' => 'Draft'], 1);

    expect(fn () => $page->discardDraft())->toThrow(RuntimeException::class);
    expect(AutosaveManager::restoreDraft($key))->not->toBeNull();
});

test('discarding a draft purges the cache and resets the availability flag', function () {
    $page = makeCreatePage();
    $key = AutosaveManager::cacheKey(get_class($page));
    Cache::put($key, ['title' => 'Draft'], 3600);
    $page->autosaveHasDraft = true;

    $page->discardDraft();

    expect(Cache::get($key))->toBeNull();
    expect($page->autosaveHasDraft)->toBeFalse();
});

test('upload filtering evicts temporary files while keeping neighbours intact', function () {
    $page = makeCreatePage();

    $upload = Mockery::mock(TemporaryUploadedFile::class);

    $result = (fn (array $d) => $this->stripFileUploads($d))->call($page, [
        'title' => 'Hello',
        'avatar' => $upload,
        'attachments' => [$upload],
        'count' => 5,
    ]);

    expect($result)->toBe(['title' => 'Hello', 'attachments' => [], 'count' => 5]);
});

test('upload filtering spares the remaining fields inside repeater rows', function () {
    $page = makeCreatePage();

    $upload = Mockery::mock(TemporaryUploadedFile::class);

    $result = (fn (array $d) => $this->stripFileUploads($d))->call($page, [
        'title' => 'Post',
        'items' => [
            ['name' => 'a', 'photo' => $upload],
            ['name' => 'b'],
        ],
    ]);

    expect($result)->toBe([
        'title' => 'Post',
        'items' => [
            ['name' => 'a'],
            ['name' => 'b'],
        ],
    ]);
});

test('upload filtering hangs on to scalars and non-upload objects', function () {
    $page = makeCreatePage();

    $carbon = Carbon::parse('2026-04-20');

    $result = (fn (array $d) => $this->stripFileUploads($d))->call($page, [
        'title' => 'Hello',
        'count' => 42,
        'active' => true,
        'tags' => ['a', 'b'],
        'at' => $carbon,
    ]);

    expect($result)->toHaveKey('title', 'Hello');
    expect($result)->toHaveKey('count', 42);
    expect($result)->toHaveKey('active', true);
    expect($result)->toHaveKey('tags');
    expect($result)->toHaveKey('at');
});

test('emptying every form field preserves the explicit deletion in the draft', function () {
    $page = makeCreatePage(['title' => 'Initial']);
    $page->mountHasAutosaveForCreate();

    $page->form->setState(['title' => 'Something']);
    $page->autosave();

    $key = AutosaveManager::cacheKey(get_class($page));
    expect(Cache::get($key))->not->toBeNull();

    $page->form->setState(['title' => '']);
    $page->autosave();

    expect(Cache::get($key))->toBe(['title' => '']);
});

test('a second autosave call skips data the first already stored', function () {
    $page = makeCreatePage(['title' => 'Initial']);
    $page->mountHasAutosaveForCreate();

    $page->form->setState(['title' => 'Changed']);
    $page->autosave();

    $key = AutosaveManager::cacheKey(get_class($page));
    $firstWrite = Cache::get($key);
    Cache::forget($key);

    $page->autosave();

    expect(Cache::get($key))->toBeNull();
    expect($firstWrite)->toBe(['title' => 'Changed']);
});

test('drafts keep zero, false, and explicit empty values', function () {
    $page = makeCreatePage();
    $page->mountHasAutosaveForCreate();
    $page->form->setState(['count' => 0, 'enabled' => false, 'note' => '', 'empty' => null]);
    $page->autosave();

    expect(AutosaveManager::restoreDraft(AutosaveManager::cacheKey($page::class)))
        ->toBe(['count' => 0, 'enabled' => false, 'note' => '', 'empty' => null]);
});

test('a page denying access blocks both draft saves and restores', function (string $action) {
    $page = new class extends AutosaveCreateFormComponent
    {
        public function authorizeAccess(): void
        {
            throw new RuntimeException('Access denied');
        }
    };
    $page->mount();
    $key = AutosaveManager::cacheKey($page::class);
    AutosaveManager::storeDraft($key, ['title' => 'Private'], 1);
    $page->data = ['title' => 'Changed'];
    $page->{$action}();

    expect($page->data)->toBe(['title' => 'Changed'])
        ->and(AutosaveManager::restoreDraft($key))->toBe(['title' => 'Private']);
})->with(['autosave', 'restoreDraft']);
