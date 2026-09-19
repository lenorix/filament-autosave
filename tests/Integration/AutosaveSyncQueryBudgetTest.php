<?php

use Illuminate\Support\Facades\DB;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\RelationshipEditPost;
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
