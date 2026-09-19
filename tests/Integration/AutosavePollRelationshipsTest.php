<?php

use Illuminate\Support\Facades\Storage;
use Lenorix\FilamentAutosave\AutosavePlugin;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms\PollRelationsRecordForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Author;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\PollItem;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\PollNote;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\PollPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\PollRelations\PollEditPost;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Opt-in polling of relationship, upload and media fields: another editor's
 * change to a relation this user has not touched shows up on the next poll;
 * a relation this user is editing is reported as stale and left alone.
 */

beforeEach(function () {
    config(['filament-autosave.poll_relationships' => true]);
    Storage::fake('public');
    $migration = require __DIR__.'/../../vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub';
    $migration->up();
});

/** @return array<string, mixed>|null */
function lastPollPayload(Testable $page): ?array
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

/** @return list<string> */
function repeaterLabels(Testable $page, string $path = 'data.items'): array
{
    return array_values(array_map(static fn (array $row): string => (string) $row['label'], $page->get($path) ?? []));
}

test('rows another editor added, removed and reordered in a relationship repeater appear on poll', function () {
    $post = PollPost::create(['title' => 'Post']);
    $post->items()->create(['label' => 'First', 'position' => 1]);
    $second = $post->items()->create(['label' => 'Second', 'position' => 2]);

    $page = Livewire::test(PollEditPost::class, ['record' => $post->getKey()]);
    expect(repeaterLabels($page))->toBe(['First', 'Second']);

    // Another editor: drop "Second", add "Third" ahead of "First".
    $second->delete();
    $post->items()->create(['label' => 'Third', 'position' => 0]);

    $page->call('syncAutosave');

    expect(repeaterLabels($page))->toBe(['Third', 'First'])
        ->and(lastPollPayload($page)['refreshed'] ?? [])->toHaveKey('items')
        ->and(lastPollPayload($page)['stale'] ?? null)->toBe([]);
});

test('a child-row edit that leaves the parent record untouched is still noticed', function () {
    $post = PollPost::create(['title' => 'Post']);
    $item = $post->items()->create(['label' => 'Original', 'position' => 1]);
    $page = Livewire::test(PollEditPost::class, ['record' => $post->getKey()]);

    $this->travel(1)->seconds();
    $item->update(['label' => 'Renamed elsewhere']);

    $page->call('syncAutosave');

    expect(repeaterLabels($page))->toBe(['Renamed elsewhere'])
        ->and($post->fresh()->updated_at->equalTo($post->updated_at))->toBeTrue();
});

test('a locally edited relationship row is kept and the field reported stale', function () {
    $post = PollPost::create(['title' => 'Post']);
    $item = $post->items()->create(['label' => 'Original', 'position' => 1]);
    $page = Livewire::test(PollEditPost::class, ['record' => $post->getKey()]);

    $rows = $page->get('data.items');
    $key = array_key_first($rows);
    $page->set("data.items.{$key}.label", 'Mine, unsaved');

    $this->travel(1)->seconds();
    $item->update(['label' => 'Theirs']);

    $page->call('syncAutosave');

    expect(repeaterLabels($page))->toBe(['Mine, unsaved'])
        ->and(lastPollPayload($page)['stale'] ?? null)->toBe(['items'])
        ->and(lastPollPayload($page)['refreshed'] ?? null)->toBe([]);
});

test('a refilled relationship is acknowledged so the next save does not write it back', function () {
    $post = PollPost::create(['title' => 'Post']);
    $post->items()->create(['label' => 'First', 'position' => 1]);
    $page = Livewire::test(PollEditPost::class, ['record' => $post->getKey()]);

    $this->travel(1)->seconds();
    $post->items()->create(['label' => 'Added elsewhere', 'position' => 2]);
    $page->call('syncAutosave');
    expect(repeaterLabels($page))->toBe(['First', 'Added elsewhere']);

    $page->set('data.title', 'Changed title')->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');

    expect(PollItem::query()->where('poll_post_id', $post->getKey())->pluck('label')->all())->toBe(['First', 'Added elsewhere'])
        ->and(PollItem::query()->count())->toBe(2);
});

test('a pivot change another editor made shows up in a BelongsToMany field', function () {
    $ann = Author::create(['name' => 'Ann']);
    $bob = Author::create(['name' => 'Bob']);
    $post = PollPost::create(['title' => 'Post']);
    $post->authors()->attach($ann);

    $page = Livewire::test(PollEditPost::class, ['record' => $post->getKey()]);
    expect(array_map('intval', $page->get('data.authors')))->toBe([$ann->getKey()]);

    $this->travel(1)->seconds();
    $post->authors()->sync([$bob->getKey()]);

    $page->call('syncAutosave');

    expect(array_map('intval', $page->get('data.authors')))->toBe([$bob->getKey()])
        ->and(lastPollPayload($page)['refreshed'] ?? [])->toHaveKey('authors');
});

test('a media collection change another editor made shows up on poll', function () {
    $post = PollPost::create(['title' => 'Post']);
    $page = Livewire::test(PollEditPost::class, ['record' => $post->getKey()]);
    expect($page->get('data.gallery'))->toBe([]);

    $theirs = $post->addMediaFromString('theirs')->usingFileName('theirs.txt')->toMediaCollection('default', 'public');

    $page->call('syncAutosave');

    expect(array_values($page->get('data.gallery')))->toBe([$theirs->uuid])
        ->and(lastPollPayload($page)['refreshed'] ?? [])->toHaveKey('gallery');
});

test('an upload column change another editor made shows up on poll', function () {
    Storage::disk('public')->put('theirs.txt', 'theirs');
    $post = PollPost::create(['title' => 'Post']);
    $page = Livewire::test(PollEditPost::class, ['record' => $post->getKey()]);

    $this->travel(1)->seconds();
    $post->update(['attachment' => 'theirs.txt']);

    $page->call('syncAutosave');

    expect(array_values((array) $page->get('data.attachment')))->toBe(['theirs.txt'])
        ->and(lastPollPayload($page)['refreshed'] ?? [])->toHaveKey('attachment');
});

test('rows of a relation without timestamps are refreshed too, by comparing state', function () {
    $post = PollPost::create(['title' => 'Post']);
    $note = PollNote::create(['poll_post_id' => $post->getKey(), 'body' => 'Original']);
    $page = Livewire::test(PollEditPost::class, ['record' => $post->getKey()]);

    $note->update(['body' => 'Edited elsewhere']);

    $page->call('syncAutosave');

    expect(array_values(array_map(static fn (array $row): string => $row['body'], $page->get('data.notes'))))->toBe(['Edited elsewhere'])
        ->and(lastPollPayload($page)['refreshed'] ?? [])->toHaveKey('notes');
});

test('nothing is written and nothing is reported when no relation changed', function () {
    $post = PollPost::create(['title' => 'Post']);
    $post->items()->create(['label' => 'First', 'position' => 1]);
    $page = Livewire::test(PollEditPost::class, ['record' => $post->getKey()]);

    $page->call('syncAutosave');

    expect(lastPollPayload($page))->toBeNull()
        ->and(PollItem::query()->count())->toBe(1);
});

test('relationship polling is off by default and off with the plugin switch', function () {
    config(['filament-autosave.poll_relationships' => false]);
    $post = PollPost::create(['title' => 'Post']);
    $page = Livewire::test(PollEditPost::class, ['record' => $post->getKey()]);

    $post->items()->create(['label' => 'Added elsewhere', 'position' => 1]);
    $page->call('syncAutosave');

    expect(repeaterLabels($page))->toBe([]);

    AutosavePlugin::resolve()->pollRelationships(true);
    $page->call('syncAutosave');

    expect(repeaterLabels($page))->toBe(['Added elsewhere']);
    AutosavePlugin::resolve()->pollRelationships(false);
});

test('a record-backed generic form refreshes a clean relationship and keeps a dirty one', function () {
    $post = PollPost::create(['title' => 'Post']);
    $post->items()->create(['label' => 'First', 'position' => 1]);
    $ann = Author::create(['name' => 'Ann']);

    $page = Livewire::test(PollRelationsRecordForm::class, ['record' => $post]);

    $rows = $page->get('data.items');
    $page->set('data.items.'.array_key_first($rows).'.label', 'Mine');

    $this->travel(1)->seconds();
    $post->authors()->attach($ann);
    $post->items()->create(['label' => 'Theirs', 'position' => 2]);

    $page->call('syncAutosave');

    expect(array_map('intval', $page->get('data.authors')))->toBe([$ann->getKey()])
        ->and(repeaterLabels($page))->toBe(['Mine'])
        ->and(lastPollPayload($page))->toMatchArray(['stale' => ['items']])
        ->and(lastPollPayload($page)['refreshed'])->toHaveKey('authors');
});

test('the poll never touches Undo snapshots or the database', function () {
    $post = PollPost::create(['title' => 'Post']);
    $post->items()->create(['label' => 'First', 'position' => 1]);
    $page = Livewire::test(PollEditPost::class, ['record' => $post->getKey()]);

    $page->set('data.title', 'Changed')->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved')
        ->assertSet('autosaveCanUndo', true);

    $this->travel(1)->seconds();
    $post->items()->create(['label' => 'Theirs', 'position' => 2]);
    $page->call('syncAutosave');

    expect(repeaterLabels($page))->toBe(['First', 'Theirs'])
        ->and($page->get('autosaveCanUndo'))->toBeTrue()
        ->and(PollItem::query()->count())->toBe(2);

    $page->call('undoAutosave');

    expect($post->fresh()->title)->toBe('Post')
        ->and(PollItem::query()->count())->toBe(2);
});

test('an upload another editor stored is not re-uploaded by a later save of this tab', function () {
    Storage::disk('public')->put('theirs.txt', 'theirs');
    $post = PollPost::create(['title' => 'Post']);
    $page = Livewire::test(PollEditPost::class, ['record' => $post->getKey()]);

    $this->travel(1)->seconds();
    $post->update(['attachment' => 'theirs.txt']);
    $page->call('syncAutosave');
    expect(array_values((array) $page->get('data.attachment')))->toBe(['theirs.txt']);

    $page->set('data.title', 'Changed')->call('autosave')->assertDispatched('autosave-status', status: 'saved');

    expect($post->fresh()->attachment)->toBe('theirs.txt')
        ->and(Storage::disk('public')->allFiles())->toBe(['theirs.txt']);
});
