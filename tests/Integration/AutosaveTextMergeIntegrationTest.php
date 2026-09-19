<?php

use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Lenorix\FilamentAutosave\AutosaveSync;
use Lenorix\FilamentAutosave\AutosaveTextMerge;
use Lenorix\FilamentAutosave\Events\AutosaveConflict;
use Lenorix\FilamentAutosave\Events\AutosaveSaved;
use Lenorix\FilamentAutosave\Events\AutosaveSynced;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages\MergeEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages\MergeNonTextEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms\AutosaveMergeRecordForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\EditPost;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

function mergePatch(string $base, string $ours): string
{
    return (new AutosaveTextMerge)->makePatch($base, $ours);
}

/** @return array<string, mixed>|null The payload of the last `autosave-status` event with the given status. */
function lastMergeStatus(Testable $page, string $status): ?array
{
    $found = null;

    try {
        $page->assertDispatched('autosave-status', function (string $event, array $params) use (&$found, $status): bool {
            if (($params['status'] ?? null) === $status) {
                $found = $params;
            }

            return true;
        });
    } catch (Throwable) {
        // No status event dispatched at all.
    }

    return $found;
}

/**
 * Change `$column` behind autosave's back right before the conditional write
 * of `$watch` (default: the same column) runs, `$times` times (null = every time).
 */
function contendMergeColumn(Post $post, string $column, callable $value, ?int $times = 1, ?string $watch = null): Closure
{
    $remaining = $times;
    $busy = false;
    $active = true;
    $watch ??= $column;

    DB::connection()->beforeExecuting(function (string $query) use ($post, $column, $value, $watch, &$remaining, &$busy, &$active): void {
        if (! $active || $busy || ! str_contains($query, 'update') || ! str_contains($query, "and \"{$watch}\" = ?")) {
            return;
        }

        if ($remaining !== null && $remaining-- <= 0) {
            return;
        }

        $busy = true;
        DB::table($post->getTable())->where('id', $post->getKey())->update([$column => $value()]);
        $busy = false;
    });

    return function () use (&$active): void {
        $active = false;
    };
}

// --- Edit pages -------------------------------------------------------------

test('two editors changing different parts of a mergeable field both keep their change', function () {
    $post = Post::create(['title' => 'The quick brown fox', 'slug' => 'fox']);
    $a = Livewire::test(MergeEditPost::class, ['record' => $post->getKey()]);
    $b = Livewire::test(MergeEditPost::class, ['record' => $post->getKey()]);

    $a->set('data.title', 'A quick brown fox')
        ->call('autosave', ['title' => mergePatch('The quick brown fox', 'A quick brown fox')]);

    $b->set('data.title', 'The quick brown fox jumps')
        ->call('autosave', ['title' => mergePatch('The quick brown fox', 'The quick brown fox jumps')]);

    expect($post->fresh()->title)->toBe('A quick brown fox jumps')
        ->and($b->get('data.title'))->toBe('A quick brown fox jumps')
        ->and(lastMergeStatus($b, 'saved'))->toMatchArray([
            'v' => AutosaveSync::VERSION,
            'merged' => ['title' => 'A quick brown fox jumps'],
            'conflicts' => [],
            'patches' => [],
        ]);
});

test('the merged value is acknowledged so the next autosave has nothing to write', function () {
    $post = Post::create(['title' => 'The quick brown fox', 'slug' => 'fox']);
    $b = Livewire::test(MergeEditPost::class, ['record' => $post->getKey()]);

    Post::query()->whereKey($post->getKey())->update(['title' => 'A quick brown fox']);

    $b->set('data.title', 'The quick brown fox jumps')
        ->call('autosave', ['title' => mergePatch('The quick brown fox', 'The quick brown fox jumps')]);

    Post::query()->whereKey($post->getKey())->update(['title' => 'Someone else again']);
    $b->call('autosave');

    expect($post->fresh()->title)->toBe('Someone else again');
});

test('an overlapping change is resolved last-write-wins in that range only and reported', function () {
    $post = Post::create(['title' => 'alpha beta gamma delta epsilon', 'slug' => 'greek']);
    $a = Livewire::test(MergeEditPost::class, ['record' => $post->getKey()]);
    $b = Livewire::test(MergeEditPost::class, ['record' => $post->getKey()]);

    $a->set('data.title', 'alpha BETA gamma delta EPSILON')
        ->call('autosave', ['title' => mergePatch('alpha beta gamma delta epsilon', 'alpha BETA gamma delta EPSILON')]);

    $b->set('data.title', 'alpha beta2 gamma delta epsilon')
        ->call('autosave', ['title' => mergePatch('alpha beta gamma delta epsilon', 'alpha beta2 gamma delta epsilon')]);

    expect($post->fresh()->title)->toBe('alpha beta2 gamma delta EPSILON')
        ->and(lastMergeStatus($b, 'saved'))->toMatchArray([
            'merged' => ['title' => 'alpha beta2 gamma delta EPSILON'],
            'conflicts' => ['title' => [['ours' => 'beta2', 'theirs' => 'BETA', 'position' => 6, 'reason' => 'overlap']]],
        ]);
});

test('undo after a merge restores the value the other editor had written', function () {
    $post = Post::create(['title' => 'one two three', 'slug' => 'numbers']);
    $b = Livewire::test(MergeEditPost::class, ['record' => $post->getKey()]);

    Post::query()->whereKey($post->getKey())->update(['title' => 'one TWO three']);

    $b->set('data.title', 'one 2 three')
        ->call('autosave', ['title' => mergePatch('one two three', 'one 2 three')]);

    expect($post->fresh()->title)->toBe('one 2 three');

    $b->call('undoAutosave');

    expect($post->fresh()->title)->toBe('one TWO three');
});

test('a client that knows the base can send it instead of a patch', function () {
    $post = Post::create(['title' => 'The quick brown fox', 'slug' => 'fox']);
    $b = Livewire::test(MergeEditPost::class, ['record' => $post->getKey()]);

    Post::query()->whereKey($post->getKey())->update(['title' => 'A quick brown fox']);

    $b->set('data.title', 'The quick brown fox jumps')
        ->call('autosave', ['title' => ['base' => 'The quick brown fox', 'ours' => 'The quick brown fox jumps']]);

    expect($post->fresh()->title)->toBe('A quick brown fox jumps');
});

test('a mergeable field saved without a patch stays last-write-wins', function () {
    $post = Post::create(['title' => 'The quick brown fox', 'slug' => 'fox']);
    $b = Livewire::test(MergeEditPost::class, ['record' => $post->getKey()]);

    Post::query()->whereKey($post->getKey())->update(['title' => 'A quick brown fox']);

    $b->set('data.title', 'The quick brown fox jumps')->call('autosave');

    expect($post->fresh()->title)->toBe('The quick brown fox jumps')
        ->and(lastMergeStatus($b, 'saved'))->toMatchArray(['merged' => [], 'conflicts' => []]);
});

test('a patch the engine cannot read never takes the cycle down: the field is saved last-write-wins', function () {
    Log::spy();
    $post = Post::create(['title' => 'The quick brown fox', 'slug' => 'fox']);
    $b = Livewire::test(MergeEditPost::class, ['record' => $post->getKey()]);

    Post::query()->whereKey($post->getKey())->update(['title' => 'A quick brown fox']);

    // A truncated escape decodes to invalid UTF-8.
    $b->set('data.title', 'The quick brown fox jumps')
        ->call('autosave', ['title' => "@@ -1,19 +1,25 @@\n-%E0%A4%A\n+The quick brown fox jumps\n"])
        ->assertDispatched('autosave-status', status: 'saved');

    expect($post->fresh()->title)->toBe('The quick brown fox jumps');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'could not merge title'))->once();
});

test('a stored value that is not valid UTF-8 is never read as empty by the merge', function () {
    $post = Post::create(['title' => 'cafe au lait', 'slug' => 'fox']);
    $b = Livewire::test(MergeEditPost::class, ['record' => $post->getKey()]);

    DB::table($post->getTable())->where('id', $post->getKey())->update(['title' => "caf\xe9 au lait"]);

    $b->set('data.title', 'cafe au lait!')
        ->call('autosave', ['title' => mergePatch('cafe au lait', 'cafe au lait!')])
        ->assertDispatched('autosave-status', status: 'saved');

    expect($post->fresh()->title)->toBe('cafe au lait!');
});

test('a field not listed as mergeable ignores its patch and stays last-write-wins', function () {
    $post = Post::create(['title' => 'Title', 'slug' => 'the quick fox']);
    $b = Livewire::test(MergeEditPost::class, ['record' => $post->getKey()]);

    Post::query()->whereKey($post->getKey())->update(['slug' => 'a quick fox']);

    $b->set('data.slug', 'the quick fox jumps')
        ->call('autosave', ['slug' => mergePatch('the quick fox', 'the quick fox jumps')]);

    expect($post->fresh()->slug)->toBe('the quick fox jumps');
});

test('a page without merge fields is untouched by patches', function () {
    $post = Post::create(['title' => 'The quick brown fox', 'slug' => 'fox']);
    $b = Livewire::test(EditPost::class, ['record' => $post->getKey()]);

    Post::query()->whereKey($post->getKey())->update(['title' => 'A quick brown fox']);

    $b->set('data.title', 'The quick brown fox jumps')
        ->call('autosave', ['title' => mergePatch('The quick brown fox', 'The quick brown fox jumps')]);

    expect($post->fresh()->title)->toBe('The quick brown fox jumps');
});

test('a non-text path listed as mergeable is ignored with one warning', function () {
    Log::spy();
    $post = Post::create(['title' => 'The quick brown fox', 'slug' => 'fox', 'settings' => ['mode' => 'fast']]);
    $b = Livewire::test(MergeNonTextEditPost::class, ['record' => $post->getKey()]);

    Post::query()->whereKey($post->getKey())->update(['title' => 'A quick brown fox']);

    $b->set('data.title', 'The quick brown fox jumps')
        ->set('data.settings.mode', 'slow')
        ->call('autosave', [
            'title' => mergePatch('The quick brown fox', 'The quick brown fox jumps'),
            'settings' => mergePatch('', 'anything'),
        ]);

    expect($post->fresh()->title)->toBe('A quick brown fox jumps')
        ->and($post->fresh()->settings)->toMatchArray(['mode' => 'slow']);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'settings'));
});

test('AutosaveSaved carries the merged values and conflicts', function () {
    Event::fake([AutosaveSaved::class]);
    $post = Post::create(['title' => 'one two three', 'slug' => 'numbers']);
    $b = Livewire::test(MergeEditPost::class, ['record' => $post->getKey()]);

    Post::query()->whereKey($post->getKey())->update(['title' => 'one TWO three']);

    $b->set('data.title', 'one 2 three')
        ->call('autosave', ['title' => mergePatch('one two three', 'one 2 three')]);

    Event::assertDispatched(AutosaveSaved::class, fn (AutosaveSaved $event): bool => $event->merged === []
        && $event->conflicts === ['title' => [['ours' => '2', 'theirs' => 'TWO', 'position' => 4, 'reason' => 'overlap']]]);
});

// --- Compare-and-swap ---------------------------------------------------------

test('a change committed between reading the field and writing it is merged, not lost', function () {
    $post = Post::create(['title' => 'alpha beta gamma', 'slug' => 'greek']);
    $b = Livewire::test(MergeEditPost::class, ['record' => $post->getKey()]);

    contendMergeColumn($post, 'title', fn (): string => 'alpha beta GAMMA');

    $b->set('data.title', 'ALPHA beta gamma')
        ->call('autosave', ['title' => mergePatch('alpha beta gamma', 'ALPHA beta gamma')]);

    expect($post->fresh()->title)->toBe('ALPHA beta GAMMA')
        ->and(lastMergeStatus($b, 'saved'))->toMatchArray(['merged' => ['title' => 'ALPHA beta GAMMA'], 'conflicts' => []]);

    $b->call('undoAutosave');

    expect($post->fresh()->title)->toBe('alpha beta GAMMA');
});

test('a permanently contended field is left unwritten and reported while the other columns save', function () {
    Log::spy();
    Event::fake([AutosaveConflict::class]);
    config()->set('filament-autosave.merge_retries', 2);
    MergeEditPost::$waits = [];
    $post = Post::create(['title' => 'alpha beta gamma', 'slug' => 'greek']);
    $b = Livewire::test(MergeEditPost::class, ['record' => $post->getKey()]);
    $attempt = 0;
    $patch = mergePatch('alpha beta gamma', 'ALPHA beta gamma');

    $stop = contendMergeColumn($post, 'title', function () use (&$attempt): string {
        return 'other '.(++$attempt);
    }, null);

    $b->set('data.title', 'ALPHA beta gamma')
        ->set('data.slug', 'greek-letters')
        ->call('autosave', ['title' => $patch]);

    $fresh = $post->fresh();
    $conflicts = ['title' => [['ours' => 'ALPHA beta gamma', 'theirs' => 'other 3', 'position' => 0, 'reason' => 'contended']]];
    // What the browser should adopt: our patch played on the latest value.
    $merged = (new AutosaveTextMerge)->apply('other 3', $patch)->value;

    expect($fresh->slug)->toBe('greek-letters')
        ->and($fresh->title)->toBe('other 3')
        ->and($attempt)->toBe(3)
        ->and(MergeEditPost::$waits)->toBe([5, 10])
        ->and($b->get('data.title'))->toBe('ALPHA beta gamma')
        ->and($b->get('data.slug'))->toBe('greek-letters')
        ->and(lastMergeStatus($b, 'saved'))->toBeNull()
        ->and(lastMergeStatus($b, 'validation'))->toMatchArray([
            'errors' => [],
            'pending' => ['title'],
            'merged' => ['title' => $merged],
            'conflicts' => $conflicts,
            'patches' => ['title' => ['theirs' => 'other 3', 'hash' => hash('xxh128', 'other 3')]],
        ]);
    Event::assertDispatched(AutosaveConflict::class, fn (AutosaveConflict $event): bool => $event->conflicts === $conflicts);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'title') && str_contains($message, '3 attempts'));

    // The user's text is still dirty. The browser adopts `merged`, rebases on
    // `theirs`, and its next cycle writes exactly that once contention ends.
    $stop();
    $b->set('data.title', $merged)
        ->call('autosave', ['title' => mergePatch('other 3', $merged)]);

    expect($post->fresh()->title)->toBe($merged)
        ->and(lastMergeStatus($b, 'saved'))->toMatchArray(['pending' => [], 'merged' => [], 'conflicts' => [], 'patches' => []]);
});

test('retries back off from 5 ms doubling to 100 ms', function () {
    MergeEditPost::$waits = [];
    $post = Post::create(['title' => 'alpha beta gamma', 'slug' => 'greek']);
    $b = Livewire::test(MergeEditPost::class, ['record' => $post->getKey()]);
    $attempt = 0;

    contendMergeColumn($post, 'title', function () use (&$attempt): string {
        return 'other '.(++$attempt);
    }, null);

    $b->set('data.title', 'ALPHA beta gamma')
        ->call('autosave', ['title' => mergePatch('alpha beta gamma', 'ALPHA beta gamma')]);

    expect(MergeEditPost::$waits)->toBe([5, 10, 20, 40, 80, 100, 100, 100, 100, 100])
        ->and(array_sum(MergeEditPost::$waits))->toBe(655)
        ->and($attempt)->toBe(11);
});

test('the re-read after a failed conditional write is a locking read', function () {
    // Under MySQL REPEATABLE READ a plain SELECT inside the cycle's
    // transaction returns the snapshot taken by the first read, so every
    // retry would merge the same stale value and end contended. SQLite has
    // no snapshot to expose this, so the SQL is pinned through the MySQL
    // grammar in pretend mode.
    config(['database.connections.pretend-mysql' => ['driver' => 'mysql', 'database' => 'pretend']]);
    app('db')->extend('pretend-mysql', fn (array $config, string $name): MySqlConnection => new MySqlConnection(
        fn () => throw new LogicException('pretend mode never touches PDO'), 'pretend', '', [...$config, 'name' => $name],
    ));
    $post = Post::create(['title' => 'alpha beta gamma', 'slug' => 'greek']);
    $page = Livewire::test(MergeEditPost::class, ['record' => $post->getKey()])->instance();
    $post->setConnection('pretend-mysql');

    $queries = DB::connection('pretend-mysql')->pretend(function () use ($page, $post): void {
        (fn () => $this->autosaveMergeCurrentValue($post, 'title'))->call($page);
    });

    expect($queries)->toHaveCount(1)
        ->and(strtolower($queries[0]['query']))->toContain('for update');
});

test('a concurrent change to another column does not cause a retry', function () {
    $post = Post::create(['title' => 'alpha beta gamma', 'slug' => 'greek']);
    $b = Livewire::test(MergeEditPost::class, ['record' => $post->getKey()]);
    $writes = 0;

    DB::connection()->beforeExecuting(function (string $query) use (&$writes): void {
        if (str_contains($query, 'update') && str_contains($query, 'and "title" = ?')) {
            $writes++;
        }
    });
    contendMergeColumn($post, 'slug', fn (): string => 'changed-elsewhere', watch: 'title');

    $b->set('data.title', 'ALPHA beta gamma')
        ->call('autosave', ['title' => mergePatch('alpha beta gamma', 'ALPHA beta gamma')]);

    expect($post->fresh()->title)->toBe('ALPHA beta gamma')
        ->and($post->fresh()->slug)->toBe('changed-elsewhere')
        ->and($writes)->toBe(1);
});

// --- Generic forms ------------------------------------------------------------

test('a record-backed generic form merges like the edit page', function () {
    $post = Post::create(['title' => 'The quick brown fox', 'slug' => 'fox']);
    $b = Livewire::test(AutosaveMergeRecordForm::class, ['record' => $post]);

    Post::query()->whereKey($post->getKey())->update(['title' => 'A quick brown fox']);

    $b->set('data.title', 'The quick brown fox jumps')
        ->call('autosave', ['title' => mergePatch('The quick brown fox', 'The quick brown fox jumps')]);

    expect($post->fresh()->title)->toBe('A quick brown fox jumps')
        ->and($b->get('data.title'))->toBe('A quick brown fox jumps')
        ->and(lastMergeStatus($b, 'saved'))->toMatchArray(['merged' => ['title' => 'A quick brown fox jumps'], 'conflicts' => []]);

    Post::query()->whereKey($post->getKey())->update(['title' => 'Someone else again']);
    $b->call('autosave');

    expect($post->fresh()->title)->toBe('Someone else again');
});

test('a generic form merges a change committed between read and write and can undo it', function () {
    $post = Post::create(['title' => 'alpha beta gamma', 'slug' => 'greek']);
    $b = Livewire::test(AutosaveMergeRecordForm::class, ['record' => $post]);

    contendMergeColumn($post, 'title', fn (): string => 'alpha beta GAMMA');

    $b->set('data.title', 'ALPHA beta gamma')
        ->call('autosave', ['title' => mergePatch('alpha beta gamma', 'ALPHA beta gamma')]);

    expect($post->fresh()->title)->toBe('ALPHA beta GAMMA');

    $b->call('undoAutosave');

    expect($post->fresh()->title)->toBe('alpha beta GAMMA');
});

test('a generic form keeps a permanently contended field dirty and retries it next cycle', function () {
    config()->set('filament-autosave.merge_retries', 1);
    AutosaveMergeRecordForm::$waits = [];
    $post = Post::create(['title' => 'alpha beta gamma', 'slug' => 'greek']);
    $b = Livewire::test(AutosaveMergeRecordForm::class, ['record' => $post]);
    $attempt = 0;
    $patch = mergePatch('alpha beta gamma', 'ALPHA beta gamma');

    $stop = contendMergeColumn($post, 'title', function () use (&$attempt): string {
        return 'other '.(++$attempt);
    }, null);

    $b->set('data.title', 'ALPHA beta gamma')
        ->set('data.slug', 'greek-letters')
        ->call('autosave', ['title' => $patch]);

    $merged = (new AutosaveTextMerge)->apply('other 2', $patch)->value;

    expect($post->fresh()->slug)->toBe('greek-letters')
        ->and($post->fresh()->title)->toBe('other 2')
        ->and(AutosaveMergeRecordForm::$waits)->toBe([5])
        ->and($b->get('data.title'))->toBe('ALPHA beta gamma')
        ->and(lastMergeStatus($b, 'validation'))->toMatchArray([
            'pending' => ['title'],
            'merged' => ['title' => $merged],
            'conflicts' => ['title' => [['ours' => 'ALPHA beta gamma', 'theirs' => 'other 2', 'position' => 0, 'reason' => 'contended']]],
            'patches' => ['title' => ['theirs' => 'other 2', 'hash' => hash('xxh128', 'other 2')]],
        ]);

    $b->call('undoAutosave');

    expect($post->fresh()->title)->toBe('other 2')
        ->and($post->fresh()->slug)->toBe('greek');

    $stop();
    $b->set('data.title', $merged)->call('autosave', ['title' => mergePatch('other 2', $merged)]);

    expect($post->fresh()->title)->toBe($merged)
        ->and(lastMergeStatus($b, 'saved'))->toMatchArray(['pending' => [], 'conflicts' => []]);
});

// --- Polling ------------------------------------------------------------------

test('a poll hands a dirty mergeable field the other editor\'s value as a patch', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $a = Livewire::test(MergeEditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Typing locally');

    Post::query()->whereKey($post->getKey())->update(['title' => 'Changed elsewhere', 'slug' => 'also-changed']);

    $a->call('syncAutosave');

    expect($a->get('data.title'))->toBe('Typing locally')
        ->and(lastMergeStatus($a, 'synced'))->toBe([
            'status' => 'synced',
            'v' => AutosaveSync::VERSION,
            'refreshed' => ['slug' => 'also-changed'],
            'stale' => ['title'],
            'patches' => ['title' => ['theirs' => 'Changed elsewhere', 'hash' => hash('xxh128', 'Changed elsewhere')]],
            'conflicts' => [],
        ]);
});

test('a poll skips the patch when the client already holds that value', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $a = Livewire::test(MergeEditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Typing locally');

    Post::query()->whereKey($post->getKey())->update(['title' => 'Changed elsewhere']);

    $a->call('syncAutosave', ['title' => hash('xxh128', 'Changed elsewhere')]);

    expect(lastMergeStatus($a, 'synced'))->toMatchArray(['stale' => ['title'], 'patches' => []]);
});

test('a poll on a clean mergeable field refreshes it without a patch', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $a = Livewire::test(MergeEditPost::class, ['record' => $post->getKey()]);

    Post::query()->whereKey($post->getKey())->update(['title' => 'Changed elsewhere']);

    $a->call('syncAutosave');

    expect($a->get('data.title'))->toBe('Changed elsewhere')
        ->and(lastMergeStatus($a, 'synced'))->toMatchArray(['refreshed' => ['title' => 'Changed elsewhere'], 'stale' => [], 'patches' => []]);
});

test('a dirty non-mergeable field stays stale without a patch', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $a = Livewire::test(MergeEditPost::class, ['record' => $post->getKey()])
        ->set('data.slug', 'typing');

    Post::query()->whereKey($post->getKey())->update(['slug' => 'changed-elsewhere']);

    $a->call('syncAutosave');

    expect(lastMergeStatus($a, 'synced'))->toMatchArray(['stale' => ['slug'], 'patches' => []]);
});

test('AutosaveSynced carries the patches', function () {
    Event::fake([AutosaveSynced::class]);
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $a = Livewire::test(AutosaveMergeRecordForm::class, ['record' => $post])
        ->set('data.title', 'Typing locally');

    Post::query()->whereKey($post->getKey())->update(['title' => 'Changed elsewhere']);

    $a->call('syncAutosave');

    Event::assertDispatched(AutosaveSynced::class, fn (AutosaveSynced $event): bool => $event->stale === ['title']
        && $event->patches === ['title' => ['theirs' => 'Changed elsewhere', 'hash' => hash('xxh128', 'Changed elsewhere')]]);
});

// --- Configuration ------------------------------------------------------------

test('merge fields resolve from the page, then the plugin, then the config', function () {
    config()->set('filament-autosave.merge_fields', ['slug']);
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    expect(Livewire::test(EditPost::class, ['record' => $post->getKey()])->instance()->getAutosaveMergeFields())->toBe(['slug'])
        ->and(Livewire::test(MergeEditPost::class, ['record' => $post->getKey()])->instance()->getAutosaveMergeFields())->toBe(['title']);
});
