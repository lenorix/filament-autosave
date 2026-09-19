<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\BudgetMediaPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\BudgetMediaPostItem;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\PostItem;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\EditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Relationship\RelationshipEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload\BudgetMediaEditPost;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');
    $migration = require __DIR__.'/../../vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub';
    $migration->up();
});

/** Count only the queries issued by the autosave request itself. */
function countAutosaveQueries(Testable $page): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $page->call('autosave')->assertDispatched('autosave-status', status: 'saved');

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

test('a plain column change on an edit page stays within its query budget', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Changed');

    $queries = countAutosaveQueries($page);

    expect($post->fresh()->title)->toBe('Changed')
        // Measured: 3 (load record, UPDATE, refresh). Ceiling leaves room for
        // one extra read without hiding a per-field regression.
        ->and($queries)->toBeLessThanOrEqual(5);
});

test('editing one row of a twenty-row relationship repeater stays within its query budget', function () {
    $post = Post::create(['title' => 'Post']);
    for ($i = 1; $i <= 20; $i++) {
        PostItem::create(['post_id' => $post->getKey(), 'label' => "Row {$i}", 'position' => $i]);
    }

    $page = Livewire::test(RelationshipEditPost::class, ['record' => $post->getKey()]);
    $key = array_key_first($page->get('data.items'));
    $page->set("data.items.{$key}.label", 'Edited');

    $queries = countAutosaveQueries($page);

    expect(PostItem::query()->where('label', 'Edited')->count())->toBe(1)
        // Measured: 10, independent of row count -- Filament's
        // saveToRelationship() only UPDATEs the changed row, and the Undo
        // snapshot/refresh reads are per relationship, not per row. A per-row
        // regression would push this past 30.
        ->and($queries)->toBeLessThanOrEqual(15);
});

test('a top-level column change on a form with ten media-bearing rows stays within its query budget', function () {
    $post = BudgetMediaPost::create(['title' => 'Original']);
    for ($i = 1; $i <= 10; $i++) {
        $item = BudgetMediaPostItem::create(['post_id' => $post->getKey(), 'label' => "Row {$i}", 'position' => $i]);
        $item->addMediaFromString("image {$i}")->usingFileName("image-{$i}.txt")->toMediaCollection('default', 'public');
    }

    $page = Livewire::test(BudgetMediaEditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Changed');

    $queries = countAutosaveQueries($page);

    expect($post->fresh()->title)->toBe('Changed')
        // Measured: 19 = 4 record/relationship queries + 15 media reloads
        // (one `whereIn` per model class per snapshot, ~8 snapshots a cycle).
        // Before batching this was 82 (one reload per Spatie field per
        // snapshot); adding rows must not move the number.
        ->and($queries)->toBeLessThanOrEqual(25);
});
