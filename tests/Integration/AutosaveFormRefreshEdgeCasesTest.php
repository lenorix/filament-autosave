<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Author;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\AutosaveMixedRecordForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\AutosavePostForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\AutosaveTitleSlugRecordForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Edge cases for refresh_unchanged_fields on record-backed generic forms.
 *
 * Contract: after a successful save, top-level paths that are clean (hash
 * equal to the acknowledged one), not a relationship, not an upload, not
 * excluded, and present in the record's attributes are refilled from the
 * record, listed under `refreshed` on the saved status event, and re-hashed
 * as clean. Dirty paths are never touched.
 */
beforeEach(function () {
    config([
        'filament-autosave.dirty_only' => true,
        'filament-autosave.refresh_unchanged_fields' => true,
    ]);
});

/**
 * `refreshed` payload of the saved status event from the LAST request made on
 * the page (Livewire's testable only exposes the latest response's effects).
 */
function refreshedPaths(Testable $page): array
{
    $refreshed = [];

    $page->assertDispatched('autosave-status', function (string $event, array $params) use (&$refreshed): bool {
        if (($params['status'] ?? null) === 'saved') {
            $refreshed = $params['refreshed'] ?? [];
        }

        return true;
    });

    return is_array($refreshed) ? $refreshed : [];
}

test('the fixtures mount and autosave a plain column', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    Livewire::test(AutosaveTitleSlugRecordForm::class, ['record' => $post])
        ->set('data.title', 'Changed')
        ->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');

    expect($post->fresh()->title)->toBe('Changed');
});

test('a dirty field is never overwritten while a clean sibling is refreshed', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    $a = Livewire::test(AutosaveTitleSlugRecordForm::class, ['record' => $post])
        ->set('data.title', 'A title');

    Livewire::test(AutosaveTitleSlugRecordForm::class, ['record' => $post])
        ->set('data.title', 'B title')
        ->set('data.slug', 'b-slug')
        ->call('autosave');

    $a->call('autosave')->assertDispatched('autosave-status', status: 'saved');

    expect($a->get('data.title'))->toBe('A title')
        ->and($a->get('data.slug'))->toBe('b-slug')
        ->and($post->fresh()->title)->toBe('A title')
        ->and($post->fresh()->slug)->toBe('b-slug');

    $refreshed = refreshedPaths($a);
    expect($refreshed)->toHaveKey('slug', 'b-slug')->not->toHaveKey('title');
});

test('a relationship changed elsewhere is not refreshed', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $author = Author::create(['name' => 'Author']);

    $a = Livewire::test(AutosaveMixedRecordForm::class, ['record' => $post]);
    $initialAuthors = $a->get('data.authors');

    Livewire::test(AutosaveMixedRecordForm::class, ['record' => $post])
        ->set('data.authors', [$author->getKey()])
        ->set('data.slug', 'b-slug')
        ->call('autosave');

    $a->set('data.title', 'Changed')->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');

    // The plain column is refreshed; the relationship, changed in the same
    // remote write, is left exactly as this instance loaded it.
    expect($a->get('data.slug'))->toBe('b-slug')
        ->and($a->get('data.authors'))->toEqual($initialAuthors)
        ->and(refreshedPaths($a))->toHaveKey('slug')->not->toHaveKey('authors');
});

test('an upload changed elsewhere is not refreshed', function () {
    Storage::fake('public');
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    $a = Livewire::test(AutosaveMixedRecordForm::class, ['record' => $post]);
    $initialSettings = $a->get('data.settings');

    Livewire::test(AutosaveMixedRecordForm::class, ['record' => $post])
        ->set('data.settings', [UploadedFile::fake()->create('doc.txt', 1)])
        ->set('data.slug', 'b-slug')
        ->call('autosave');

    expect($post->fresh()->settings)->not->toBeEmpty();

    $a->set('data.title', 'Changed')->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');

    expect($a->get('data.slug'))->toBe('b-slug')
        ->and($a->get('data.settings'))->toEqual($initialSettings)
        ->and(refreshedPaths($a))->toHaveKey('slug')->not->toHaveKey('settings');
});

test('an excluded field is not refreshed', function () {
    config(['filament-autosave.except' => ['slug']]);
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    $a = Livewire::test(AutosaveTitleSlugRecordForm::class, ['record' => $post]);

    $post->update(['slug' => 'changed-elsewhere']);

    $a->set('data.title', 'Changed')->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');

    expect($a->get('data.slug'))->toBe('original')
        ->and(refreshedPaths($a))->not->toHaveKey('slug');
});

test('a recordless draft saves without a refresh payload', function () {
    $page = Livewire::test(AutosavePostForm::class)
        ->set('data.title', 'Draft')
        ->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved')
        ->assertSet('autosaveHasDraft', true);

    expect(refreshedPaths($page))->toBe([]);
});

test('a refreshed field is acknowledged as clean and not written again', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    $a = Livewire::test(AutosaveTitleSlugRecordForm::class, ['record' => $post]);

    Livewire::test(AutosaveTitleSlugRecordForm::class, ['record' => $post])
        ->set('data.slug', 'b-slug')
        ->call('autosave');

    $a->set('data.title', 'Changed')->call('autosave');
    expect($a->get('data.slug'))->toBe('b-slug');

    $updates = 0;
    Post::updated(function () use (&$updates): void {
        $updates++;
    });

    $a->call('autosave');

    expect($updates)->toBe(0)
        ->and(refreshedPaths($a))->not->toHaveKey('slug')
        ->and($post->fresh()->slug)->toBe('b-slug');
});

test('nothing is refreshed when refresh_unchanged_fields is disabled', function () {
    config(['filament-autosave.refresh_unchanged_fields' => false]);
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    $a = Livewire::test(AutosaveTitleSlugRecordForm::class, ['record' => $post]);

    Livewire::test(AutosaveTitleSlugRecordForm::class, ['record' => $post])
        ->set('data.slug', 'b-slug')
        ->call('autosave');

    $a->set('data.title', 'Changed')->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');

    expect($a->get('data.slug'))->toBe('original')
        ->and(refreshedPaths($a))->toBe([]);
});
