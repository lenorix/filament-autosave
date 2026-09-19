<?php

use Illuminate\Support\Facades\DB;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms\PollRelationsRecordForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\PollPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\PollRelations\PollEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\EditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Relationship\RelationshipEditPost;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/** @return array<int, string> SQL of every query one Livewire request issued. */
function queriesDuring(Testable $page, string $method): array
{
    DB::enableQueryLog();
    DB::flushQueryLog();

    $page->call($method);

    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    return $queries;
}

/**
 * Queries the poll adds on top of what any Livewire request to the same page
 * already costs (record hydration, relationship option loading during
 * render). That marginal cost is what polling every few seconds multiplies.
 *
 * @return array<int, string>
 */
function marginalPollQueries(Testable $page): array
{
    $baseline = queriesDuring($page, '$refresh');
    $poll = queriesDuring($page, 'syncAutosave');

    foreach ($baseline as $sql) {
        $index = array_search($sql, $poll, true);

        if ($index !== false) {
            unset($poll[$index]);
        }
    }

    return array_values($poll);
}

/*
 * Ceilings. An idle poll re-reads the record's own columns once (or, on a
 * timestamped model, only its updated_at); it never touches relationships,
 * so its marginal cost is one query whatever the form looks like.
 *
 * Only when another editor did write does the poll dehydrate the form to
 * find clean fields and read back what it refilled. Filament dehydrates
 * relationship components by loading their options, so that costs roughly
 * one query per relationship field per pass -- the same price the post-save
 * refresh already pays, bounded by the form, not by rows or editors.
 */

test('an idle poll adds exactly one query on a plain form', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = Livewire::test(EditPost::class, ['record' => $post->getKey()]);

    expect(marginalPollQueries($page))->toHaveCount(1);
});

test('an idle poll adds exactly one query on a relationship-heavy form and never a relationship query', function () {
    config(['filament-autosave.poll_relationships' => false]);

    $post = Post::create(['title' => 'Original']);
    $page = Livewire::test(RelationshipEditPost::class, ['record' => $post->getKey()]);

    $marginal = marginalPollQueries($page);

    expect($marginal)->toHaveCount(1)
        ->and($marginal[0])->toContain('"posts"')
        ->not->toContain('post_items')->not->toContain('author_post')->not->toContain('categories');
});

test('a poll that pulls a remote change into a plain form adds at most one query', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = Livewire::test(EditPost::class, ['record' => $post->getKey()]);

    Post::query()->whereKey($post->getKey())->update(['slug' => 'changed']);

    expect(count(marginalPollQueries($page)))->toBeLessThanOrEqual(1)
        ->and($page->get('data.slug'))->toBe('changed');
});

test('a poll that pulls a remote change into a relationship-heavy form is bounded by the form, not the data', function () {
    $post = Post::create(['title' => 'Original']);
    $page = Livewire::test(RelationshipEditPost::class, ['record' => $post->getKey()]);

    Post::query()->whereKey($post->getKey())->update(['title' => 'changed']);

    // 1 record reload + up to 2 dehydration passes x 3 relationship fields.
    expect(count(marginalPollQueries($page)))->toBeLessThanOrEqual(7)
        ->and($page->get('data.title'))->toBe('changed');
});

/*
 * With `poll_relationships` on, an idle poll pays one more query whatever the
 * form: a UNION of one `count(*)` + `max(updated_at)` row per polled relation
 * (media included). Only a relation whose rows carry no timestamps is re-read
 * on every poll, since nothing cheaper can tell an edit to its rows apart.
 */

test('an idle poll with relationship polling on adds one detector query for every relation at once', function () {
    config(['filament-autosave.poll_relationships' => true]);
    $migration = require __DIR__.'/../../vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub';
    $migration->up();

    $post = PollPost::create(['title' => 'Post']);
    $post->items()->create(['label' => 'First', 'position' => 1]);
    $page = Livewire::test(PollEditPost::class, ['record' => $post->getKey()]);

    $marginal = marginalPollQueries($page);
    $detector = array_values(array_filter($marginal, static fn (string $sql): bool => str_contains($sql, 'autosave_count')));

    // updated_at fast path + the detector + the `notes` re-read (no timestamps).
    expect($detector)->toHaveCount(1)
        ->and($detector[0])->toContain('poll_items')->toContain('author_poll_post')->toContain('media')
        ->and(count($marginal))->toBeLessThanOrEqual(3);

    foreach ($marginal as $sql) {
        if ($sql !== $detector[0]) {
            expect($sql)->not->toContain('author_poll_post');
        }
    }
});

test('an idle poll costs the detector alone when every polled relation has timestamps', function () {
    config(['filament-autosave.poll_relationships' => true]);

    $post = PollPost::create(['title' => 'Post']);
    $post->items()->create(['label' => 'First', 'position' => 1]);
    $page = Livewire::test(PollRelationsRecordForm::class, ['record' => $post]);

    $marginal = marginalPollQueries($page);

    // The updated_at fast path and the detector, nothing else: no relation is
    // read until its fingerprint moves.
    expect(array_values(array_filter($marginal, static fn (string $sql): bool => str_contains($sql, 'autosave_count'))))->toHaveCount(1)
        ->and($marginal)->toHaveCount(2);
});

test('a poll that refills a changed relation reads that relation, not the others', function () {
    config(['filament-autosave.poll_relationships' => true]);
    $migration = require __DIR__.'/../../vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub';
    $migration->up();

    $post = PollPost::create(['title' => 'Post']);
    $post->items()->create(['label' => 'First', 'position' => 1]);
    $page = Livewire::test(PollEditPost::class, ['record' => $post->getKey()]);

    $post->items()->create(['label' => 'Second', 'position' => 2]);

    $marginal = marginalPollQueries($page);

    expect(count($marginal))->toBeLessThanOrEqual(10)
        ->and(array_values(array_filter(
            $marginal,
            static fn (string $sql): bool => str_contains($sql, 'author_poll_post') && ! str_contains($sql, 'autosave_count'),
        )))->toBe([])
        ->and($page->get('data.items'))->toHaveCount(2);
});
