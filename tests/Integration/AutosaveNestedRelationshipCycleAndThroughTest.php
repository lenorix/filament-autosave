<?php

use Illuminate\Support\Facades\DB;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\CycleNode;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Cycle\CycleNodeEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Cycle\ThroughRelationEditPost;
use Livewire\Livewire;

/*
 * Nested relationship polling walks the form schema, a finite PHP tree, not
 * the underlying Eloquent relation graph. These pin that a *cyclic relation
 * type* (a model related to itself, nested inside its own schema at several
 * levels) is bounded the same way a plain deep schema is: by
 * `poll_relationship_depth`, with query cost that does not grow with row
 * count. See tests/Integration/RELATION_POLLING.md.
 */

test('a cyclic self-relation nested to the configured depth discovers a remote child, but nothing past the depth limit', function () {
    config(['filament-autosave.poll_relationships' => true, 'filament-autosave.poll_relationship_depth' => 3]);

    $root = CycleNode::create(['label' => 'Root']);
    $child = CycleNode::create(['parent_id' => $root->getKey(), 'label' => 'Child']);

    $page = Livewire::test(CycleNodeEditPost::class, ['record' => $root->getKey()]);

    // Within depth (root -> child -> grandchild is level 2, inside the
    // configured depth of 3): must be discovered.
    $grandchild = $child->children()->create(['label' => 'Grandchild']);
    $page->call('syncAutosave');

    $childRow = collect($page->get('data.children'))->firstWhere('label', 'Child');
    expect($childRow)->not->toBeNull();
    $grandchildRow = collect($childRow['children'] ?? [])->firstWhere('label', 'Grandchild');
    expect($grandchildRow)->not->toBeNull();

    // Past the configured depth (a fourth level): must not be discovered,
    // and must not error or hang either.
    $grandchild->children()->create(['label' => 'Great-grandchild']);
    $page->call('syncAutosave');

    $childRow = collect($page->get('data.children'))->firstWhere('label', 'Child');
    $grandchildRow = collect($childRow['children'] ?? [])->firstWhere('label', 'Grandchild');
    expect(collect($grandchildRow['children'] ?? []))->toBeEmpty();
});

test('a cyclic self-relation costs a bounded number of queries on an idle poll, regardless of row count', function () {
    config(['filament-autosave.poll_relationships' => true, 'filament-autosave.poll_relationship_depth' => 3]);

    $root = CycleNode::create(['label' => 'Root']);

    foreach (range(1, 5) as $i) {
        $child = $root->children()->create(['label' => "Child {$i}"]);

        foreach (range(1, 3) as $j) {
            $child->children()->create(['label' => "Grandchild {$i}.{$j}"]);
        }
    }

    $page = Livewire::test(CycleNodeEditPost::class, ['record' => $root->getKey()]);
    $instance = $page->instance();

    // Build the rendered paths from persisted parents, then exercise the same
    // batch preloader used by the production detector. This isolates detector
    // cost from Filament's own form hydration queries.
    $fields = [
        'children' => [
            'kind' => 'relation',
            'components' => [],
            'relation' => $root->children(),
            'refresh' => 'children',
            'name' => 'children',
        ],
    ];

    foreach ($root->children as $child) {
        $fields["children.{$child->getKey()}.children"] = [
            'kind' => 'relation',
            'components' => [],
            'relation' => $child->children(),
            'refresh' => 'children',
            'name' => 'children',
        ];

        foreach ($child->children as $grandchild) {
            $fields["children.{$child->getKey()}.children.{$grandchild->getKey()}.children"] = [
                'kind' => 'relation',
                'components' => [],
                'relation' => $grandchild->children(),
                'refresh' => 'children',
                'name' => 'children',
            ];
        }
    }

    DB::enableQueryLog();
    DB::flushQueryLog();
    (function (array $fields): void {
        $preloaded = $this->preloadAutosavePolledRelations(
            $fields,
            timestampFreeOnly: false,
            timestampedOnly: true,
        );

        foreach ($preloaded as $path => $rows) {
            $this->autosaveRelationFingerprintFromRows($fields[$path]['relation'], $rows);
        }
    })->call($instance, $fields);
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    $cycleNodeQueries = array_values(array_filter(
        $queries,
        static fn (string $query): bool => str_contains($query, 'cycle_nodes'),
    ));

    // One batched query per nested relationship level, never one query per
    // rendered row: 5 children and 15 grandchildren must not multiply the
    // query count.
    expect($cycleNodeQueries)->not->toBeEmpty()
        ->and(count($cycleNodeQueries))->toBeLessThanOrEqual(4);
});

test('a real cyclic poll batches repeated children relation reads', function () {
    config(['filament-autosave.poll_relationships' => true, 'filament-autosave.poll_relationship_depth' => 3]);

    $root = CycleNode::create(['label' => 'Root']);

    foreach (range(1, 5) as $i) {
        $child = $root->children()->create(['label' => "Child {$i}"]);

        foreach (range(1, 3) as $j) {
            $child->children()->create(['label' => "Grandchild {$i}.{$j}"]);
        }
    }

    // Filament's own Schema::dehydrateState() walks the whole rendered
    // component tree at the end of every Livewire request, and its
    // Repeater::getCachedExistingRecords() pays one query per nested row
    // when the relation isn't already loaded on that exact row instance —
    // regardless of polling, autosave, or this package at all. Pin that
    // baseline first (poll_relationships off, so syncAutosave no-ops almost
    // immediately) so the real assertion below measures only what *our*
    // code adds on top of a cost we do not control and cannot batch away.
    config(['filament-autosave.poll_relationships' => false]);
    $baselinePage = Livewire::test(CycleNodeEditPost::class, ['record' => $root->getKey()]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $baselinePage->call('syncAutosave');
    $baselineCount = count(array_filter(
        array_column(DB::getQueryLog(), 'query'),
        static fn (string $query): bool => str_contains($query, 'cycle_nodes'),
    ));
    DB::disableQueryLog();

    config(['filament-autosave.poll_relationships' => true, 'filament-autosave.poll_relationship_depth' => 3]);
    $page = Livewire::test(CycleNodeEditPost::class, ['record' => $root->getKey()]);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $page->call('syncAutosave');
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    $cycleNodeQueries = array_values(array_filter(
        $queries,
        static fn (string $query): bool => str_contains($query, 'cycle_nodes'),
    ));

    // Our own detection/refill code adds at most a handful of batched
    // queries (one per nested relationship level) on top of whatever
    // Filament's dehydration already costs; it never multiplies with row
    // count the way Filament's own per-row cache population does.
    expect($cycleNodeQueries)->not->toBeEmpty()
        ->and(count($cycleNodeQueries) - $baselineCount)->toBeLessThanOrEqual(6);
});

test('polling fingerprints a rendered through relation after a remote insertion', function () {
    config(['filament-autosave.poll_relationships' => true]);

    $post = Post::create(['title' => 'Post']);
    $item = $post->items()->create(['label' => 'Item', 'position' => 1]);
    $item->subitems()->create(['label' => 'Existing subitem']);

    $page = Livewire::test(ThroughRelationEditPost::class, ['record' => $post->getKey()]);
    $before = $page->instance()->probeAutosaveRelationFingerprints();

    $item->subitems()->create(['label' => 'Remote through subitem']);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $after = $page->instance()->probeAutosaveRelationFingerprints();
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    $throughQueries = array_values(array_filter(
        $queries,
        static fn (string $query): bool => str_contains($query, 'post_sub_items'),
    ));

    expect($after['fingerprints'])->not->toBe($before['fingerprints'])
        ->and($throughQueries)->toHaveCount(1);
});

test('polling sync reports a remote insertion in a through relation', function () {
    config(['filament-autosave.poll_relationships' => true]);

    $post = Post::create(['title' => 'Post']);
    $item = $post->items()->create(['label' => 'Item', 'position' => 1]);
    $item->subitems()->create(['label' => 'Existing subitem']);

    $page = Livewire::test(ThroughRelationEditPost::class, ['record' => $post->getKey()]);
    $item->subitems()->create(['label' => 'Remote through subitem']);

    $page->call('syncAutosave')->assertDispatched('autosave-status', status: 'synced');
});
