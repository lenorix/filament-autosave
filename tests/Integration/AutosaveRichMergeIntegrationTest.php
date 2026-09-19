<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Lenorix\FilamentAutosave\AutosaveSync;
use Lenorix\FilamentAutosave\Events\AutosaveConflict;
use Lenorix\FilamentAutosave\Events\AutosaveSynced;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms\RichMergeRecordForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\PlainRichPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\RichUploadPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\RichMerge\RichMergeEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\RichMerge\RichMergeHtmlEditPost;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $migration = require __DIR__.'/../../vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub';
    $migration->up();
});

/** @return array<string, mixed> A Tiptap doc of paragraphs (strings) and raw nodes (arrays). */
function richDoc(string|array ...$blocks): array
{
    return ['type' => 'doc', 'content' => array_map(
        static fn (string|array $block): array => is_array($block)
            ? $block
            : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $block]]],
        $blocks,
    )];
}

/** @return array<string, mixed> */
function richImage(string $id, ?string $src = null): array
{
    return ['type' => 'image', 'attrs' => ['id' => $id, 'src' => $src, 'alt' => null]];
}

/** @return list<string> The plain text of each block of a stored doc. */
function richTexts(array|string|null $doc): array
{
    if (! is_array($doc)) {
        return [];
    }

    return array_map(static fn (array $block): string => $block['type'] === 'image'
        ? 'image:'.$block['attrs']['id']
        : implode('', array_map(static fn (array $inline): string => $inline['text'] ?? '', $block['content'] ?? [])),
        $doc['content'] ?? []);
}

/** @return array<string, mixed>|null The payload of the last `autosave-status` event with the given status. */
function lastRichStatus(Testable $page, string $status): ?array
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

/** Change `$column` behind autosave's back right before its conditional write runs, `$times` times (null = every time). */
function contendRichColumn(RichUploadPost|PlainRichPost $post, string $column, callable $value, ?int $times = 1): Closure
{
    $remaining = $times;
    $busy = false;
    $active = true;

    DB::connection()->beforeExecuting(function (string $query) use ($post, $column, $value, &$remaining, &$busy, &$active): void {
        if (! $active || $busy || ! str_contains($query, 'update') || ! str_contains($query, "and \"{$column}\" = ?")) {
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

function richPost(array $doc): RichUploadPost
{
    return RichUploadPost::create(['title' => 'Post', 'body' => $doc]);
}

// --- JSON column, Edit page -------------------------------------------------

test('two editors changing different blocks of a rich field both keep their change', function () {
    $base = richDoc('alpha', 'beta');
    $post = richPost($base);
    $a = Livewire::test(RichMergeEditPost::class, ['record' => $post->getKey()]);
    $b = Livewire::test(RichMergeEditPost::class, ['record' => $post->getKey()]);

    $a->set('data.body', richDoc('ALPHA', 'beta'))->call('autosave', ['body' => ['base' => $base]]);
    $b->set('data.body', richDoc('alpha', 'beta', 'gamma'))->call('autosave', ['body' => ['base' => $base]]);

    expect(richTexts($post->fresh()->body))->toBe(['ALPHA', 'beta', 'gamma'])
        ->and(richTexts($b->get('data.body')))->toBe(['ALPHA', 'beta', 'gamma'])
        ->and(lastRichStatus($b, 'saved'))->toMatchArray(['v' => AutosaveSync::VERSION, 'conflicts' => [], 'patches' => []])
        ->and(richTexts(lastRichStatus($b, 'saved')['merged']['body']))->toBe(['ALPHA', 'beta', 'gamma']);
});

test('the merged rich value is acknowledged so the next autosave has nothing to write', function () {
    $base = richDoc('alpha', 'beta');
    $post = richPost($base);
    $b = Livewire::test(RichMergeEditPost::class, ['record' => $post->getKey()]);

    $post->update(['body' => richDoc('ALPHA', 'beta')]);

    $b->set('data.body', richDoc('alpha', 'beta', 'gamma'))->call('autosave', ['body' => ['base' => $base]]);

    $post->update(['body' => richDoc('someone', 'else')]);
    $b->call('autosave');

    expect(richTexts($post->fresh()->body))->toBe(['someone', 'else']);
});

test('words changed by both editors in one paragraph are merged, an overlap is reported with the losing fragment', function () {
    $base = richDoc('one two three four five');
    $post = richPost($base);
    $a = Livewire::test(RichMergeEditPost::class, ['record' => $post->getKey()]);
    $b = Livewire::test(RichMergeEditPost::class, ['record' => $post->getKey()]);

    $a->set('data.body', richDoc('one TWO three four FIVE'))->call('autosave', ['body' => ['base' => $base]]);
    $b->set('data.body', richDoc('one 2 three four five'))->call('autosave', ['body' => ['base' => $base]]);

    $status = lastRichStatus($b, 'saved');

    expect(richTexts($post->fresh()->body))->toBe(['one 2 three four FIVE'])
        ->and($status['conflicts']['body'])->toHaveCount(1)
        ->and($status['conflicts']['body'][0])->toMatchArray(['kind' => 'inline', 'reason' => 'overlap', 'block' => [0], 'position' => 4])
        ->and(richTexts(['content' => $status['conflicts']['body'][0]['ours']]))->toBe(['2'])
        ->and(richTexts(['content' => $status['conflicts']['body'][0]['theirs']]))->toBe(['TWO']);
});

test('a rich field saved without a base stays last-write-wins', function () {
    $post = richPost(richDoc('alpha'));
    $b = Livewire::test(RichMergeEditPost::class, ['record' => $post->getKey()]);

    $post->update(['body' => richDoc('ALPHA')]);
    $b->set('data.body', richDoc('alpha', 'beta'))->call('autosave');

    expect(richTexts($post->fresh()->body))->toBe(['alpha', 'beta'])
        ->and(lastRichStatus($b, 'saved'))->toMatchArray(['merged' => [], 'conflicts' => []]);
});

test('a text patch sent for a rich field is no patch', function () {
    $post = richPost(richDoc('alpha'));
    $b = Livewire::test(RichMergeEditPost::class, ['record' => $post->getKey()]);

    $post->update(['body' => richDoc('ALPHA')]);
    $b->set('data.body', richDoc('alpha', 'beta'))->call('autosave', ['body' => "@@ -1,5 +1,5 @@\n-alpha\n+beta\n"]);

    expect(richTexts($post->fresh()->body))->toBe(['alpha', 'beta']);
});

test('undo after a rich merge restores the value the other editor had written', function () {
    $base = '<p>alpha</p><p>beta</p>';
    $post = PlainRichPost::create(['title' => 'Post', 'body' => $base]);
    $b = Livewire::test(RichMergeHtmlEditPost::class, ['record' => $post->getKey()]);

    $post->update(['body' => '<p>ALPHA</p><p>beta</p>']);
    $b->set('data.body', richDoc('alpha', 'BETA'))->call('autosave', ['body' => ['base' => $base]]);

    expect($post->fresh()->body)->toBe('<p>ALPHA</p><p>BETA</p>')
        ->and($b->get('autosaveCanUndo'))->toBeTrue();

    $b->call('undoAutosave');

    expect($post->fresh()->body)->toBe('<p>ALPHA</p><p>beta</p>');
});

test('a rich field with an attachment provider keeps Undo disabled after a merge, like any file operation', function () {
    $base = richDoc('alpha', 'beta');
    $post = richPost($base);
    $b = Livewire::test(RichMergeEditPost::class, ['record' => $post->getKey()]);

    $post->update(['body' => richDoc('ALPHA', 'beta')]);
    $b->set('data.body', richDoc('alpha', 'BETA'))->call('autosave', ['body' => ['base' => $base]]);

    expect(richTexts($post->fresh()->body))->toBe(['ALPHA', 'BETA'])
        ->and($b->get('autosaveCanUndo'))->toBeFalse();
});

// --- Attachments ------------------------------------------------------------

test('an image another editor kept survives a merge that removed it locally, file and media row included', function () {
    $post = richPost(richDoc('intro'));
    $media = $post->addMediaFromString('image')->usingFileName('image.png')->toMediaCollection('content', 'public');
    $base = richDoc('intro', richImage($media->uuid, $media->getUrl()));
    $post->update(['body' => $base]);
    $path = $media->getPath();

    $a = Livewire::test(RichMergeEditPost::class, ['record' => $post->getKey()]);
    $b = Livewire::test(RichMergeEditPost::class, ['record' => $post->getKey()]);

    // B edits the caption and keeps the image; A removes the image.
    $b->set('data.body', richDoc('intro edited', richImage($media->uuid, $media->getUrl())))
        ->call('autosave', ['body' => ['base' => $base]]);
    $a->set('data.body', richDoc('intro'))->call('autosave', ['body' => ['base' => $base]]);

    // Deleted on one side, its caption paragraph untouched: the image goes.
    expect(richTexts($post->fresh()->body))->toBe(['intro edited'])
        ->and($post->fresh()->getMedia('content'))->toHaveCount(0);

    // A removes the image and saves first — its cleanup deletes the file —
    // then B, who changed the alt, saves: B's change wins the overlap, so the
    // node comes back, but a file a finished save already removed is gone.
    $media = $post->addMediaFromString('image')->usingFileName('image2.png')->toMediaCollection('content', 'public');
    $base = richDoc('intro edited', richImage($media->uuid, $media->getUrl()));
    $post->update(['body' => $base]);
    $path = $media->getPath();
    $a = Livewire::test(RichMergeEditPost::class, ['record' => $post->getKey()]);
    $b = Livewire::test(RichMergeEditPost::class, ['record' => $post->getKey()]);

    $kept = richImage($media->uuid, $media->getUrl());
    $kept['attrs']['alt'] = 'described';
    $a->set('data.body', richDoc('intro edited'))->call('autosave', ['body' => ['base' => $base]]);
    $b->set('data.body', richDoc('intro edited', $kept))->call('autosave', ['body' => ['base' => $base]]);

    expect(richTexts($post->fresh()->body))->toBe(['intro edited', 'image:'.$media->uuid])
        ->and($post->fresh()->body['content'][1]['attrs']['alt'])->toBe('described')
        ->and($post->fresh()->getMedia('content'))->toHaveCount(0)
        ->and(lastRichStatus($b, 'saved')['conflicts']['body'][0])->toMatchArray(['kind' => 'block', 'reason' => 'overlap', 'theirs' => []]);
});

test('an image the other editor added after this tab loaded is not cleaned up by a merged save', function () {
    $base = richDoc('intro');
    $post = richPost($base);
    $a = Livewire::test(RichMergeEditPost::class, ['record' => $post->getKey()]);

    $media = $post->addMediaFromString('image')->usingFileName('later.png')->toMediaCollection('content', 'public');
    $post->update(['body' => richDoc('intro', richImage($media->uuid, $media->getUrl()))]);

    $a->set('data.body', richDoc('intro, edited here'))->call('autosave', ['body' => ['base' => $base]]);

    expect(richTexts($post->fresh()->body))->toBe(['intro, edited here', 'image:'.$media->uuid])
        ->and($post->fresh()->getMedia('content'))->toHaveCount(1)
        ->and(file_exists($media->getPath()))->toBeTrue();
});

test('an image removed by this editor and untouched by the other is cleaned up after the merge', function () {
    $post = richPost(richDoc('intro'));
    $media = $post->addMediaFromString('image')->usingFileName('gone.png')->toMediaCollection('content', 'public');
    $base = richDoc('intro', richImage($media->uuid, $media->getUrl()));
    $post->update(['body' => $base]);
    $a = Livewire::test(RichMergeEditPost::class, ['record' => $post->getKey()]);

    $post->update(['body' => richDoc('intro (them)', richImage($media->uuid, $media->getUrl()))]);
    $a->set('data.body', richDoc('intro'))->call('autosave', ['body' => ['base' => $base]]);

    expect(richTexts($post->fresh()->body))->toBe(['intro (them)'])
        ->and($post->fresh()->getMedia('content'))->toHaveCount(0)
        ->and(file_exists($media->getPath()))->toBeFalse();
});

// --- Contention -------------------------------------------------------------

test('a rich change committed between reading the field and writing it is merged, not lost', function () {
    $base = richDoc('alpha', 'beta');
    $post = richPost($base);
    $b = Livewire::test(RichMergeEditPost::class, ['record' => $post->getKey()]);

    contendRichColumn($post, 'body', fn (): string => json_encode(richDoc('alpha', 'BETA')));

    $b->set('data.body', richDoc('ALPHA', 'beta'))->call('autosave', ['body' => ['base' => $base]]);

    expect(richTexts($post->fresh()->body))->toBe(['ALPHA', 'BETA']);
});

test('a permanently contended rich field is left unwritten and reported while the other columns save', function () {
    Log::spy();
    Event::fake([AutosaveConflict::class]);
    config()->set('filament-autosave.merge_retries', 2);
    RichMergeEditPost::$waits = [];
    $base = richDoc('alpha');
    $post = richPost($base);
    $b = Livewire::test(RichMergeEditPost::class, ['record' => $post->getKey()]);
    $attempt = 0;

    $stop = contendRichColumn($post, 'body', function () use (&$attempt): string {
        return json_encode(richDoc('other '.(++$attempt)));
    }, null);

    $b->set('data.body', richDoc('alpha', 'mine'))->set('data.title', 'Retitled')
        ->call('autosave', ['body' => ['base' => $base]]);

    $fresh = $post->fresh();
    $status = lastRichStatus($b, 'validation');

    expect($fresh->title)->toBe('Retitled')
        ->and(richTexts($fresh->body))->toBe(['other 3'])
        ->and(RichMergeEditPost::$waits)->toBe([5, 10])
        ->and(richTexts($b->get('data.body')))->toBe(['alpha', 'mine'])
        ->and(lastRichStatus($b, 'saved'))->toBeNull()
        ->and($status)->toMatchArray(['errors' => [], 'pending' => ['body']])
        ->and($status['conflicts']['body'][0])->toMatchArray(['reason' => 'contended'])
        ->and(richTexts($status['conflicts']['body'][0]['theirs']))->toBe(['other 3'])
        ->and(richTexts($status['patches']['body']['theirs']))->toBe(['other 3'])
        ->and($status['patches']['body']['hash'])->toBe(hash('xxh128', DB::table('posts')->where('id', $post->getKey())->value('body')))
        // What the browser should adopt: our change replayed on the latest value.
        ->and(richTexts($status['merged']['body']))->toBe(['other 3', 'mine']);
    Event::assertDispatched(AutosaveConflict::class, fn (AutosaveConflict $event): bool => array_keys($event->conflicts) === ['body']);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message): bool => str_contains($message, 'body') && str_contains($message, '3 attempts'));

    $stop();
    $b->set('data.body', $status['merged']['body'])->call('autosave', ['body' => ['base' => $status['patches']['body']['theirs']]]);

    expect(richTexts($post->fresh()->body))->toBe(['other 3', 'mine'])
        ->and(lastRichStatus($b, 'saved'))->toMatchArray(['pending' => [], 'merged' => [], 'conflicts' => [], 'patches' => []]);
});

// --- Canonical form ---------------------------------------------------------

test('a seeded non-canonical HTML value is written canonically once and the field is clean afterwards', function () {
    $seeded = '<p>Hello <strong>world</strong></p><p style="color: red">red</p>';
    $post = PlainRichPost::create(['title' => 'Post', 'body' => $seeded]);
    $b = Livewire::test(RichMergeHtmlEditPost::class, ['record' => $post->getKey()]);

    $b->set('data.body', '<p>Hello <strong>world</strong>!</p><p style="color: red">red</p>')
        ->call('autosave', ['body' => ['base' => $seeded]]);

    // The browser already holds this content: nothing to adopt.
    expect($post->fresh()->body)->toBe('<p>Hello <strong>world</strong>!</p><p>red</p>')
        ->and(lastRichStatus($b, 'saved'))->toMatchArray(['merged' => [], 'conflicts' => []]);

    // Nothing left to write: the canonical value is the acknowledged one.
    $post->update(['body' => '<p>elsewhere</p>']);
    $b->call('autosave', ['body' => ['base' => '<p>Hello <strong>world</strong>!</p><p>red</p>']]);

    expect($post->fresh()->body)->toBe('<p>elsewhere</p>');
});

// --- Polling ----------------------------------------------------------------

test('a poll refills a clean rich field with the other editor\'s value', function () {
    $post = richPost(richDoc('alpha'));
    $a = Livewire::test(RichMergeEditPost::class, ['record' => $post->getKey()]);

    $post->update(['body' => richDoc('ALPHA')]);
    $a->call('syncAutosave');

    expect(richTexts($a->get('data.body')))->toBe(['ALPHA'])
        ->and(lastRichStatus($a, 'synced'))->toMatchArray(['stale' => [], 'patches' => []])
        ->and(richTexts(lastRichStatus($a, 'synced')['refreshed']['body']))->toBe(['ALPHA']);
});

test('a poll hands a dirty rich field the other editor\'s value as a patch', function () {
    Event::fake([AutosaveSynced::class]);
    $post = richPost(richDoc('alpha'));
    $a = Livewire::test(RichMergeEditPost::class, ['record' => $post->getKey()])
        ->set('data.body', richDoc('alpha', 'typing'));

    $post->update(['body' => richDoc('ALPHA')]);
    $a->call('syncAutosave');

    $status = lastRichStatus($a, 'synced');
    $hash = hash('xxh128', DB::table('posts')->where('id', $post->getKey())->value('body'));

    expect(richTexts($a->get('data.body')))->toBe(['alpha', 'typing'])
        ->and($status)->toMatchArray(['stale' => ['body'], 'refreshed' => []])
        ->and(richTexts($status['patches']['body']['theirs']))->toBe(['ALPHA'])
        ->and($status['patches']['body']['hash'])->toBe($hash);
    Event::assertDispatched(AutosaveSynced::class, fn (AutosaveSynced $event): bool => richTexts($event->patches['body']['theirs']) === ['ALPHA']);

    // A browser already holding the latest value gets no patch for it.
    $post->update(['body' => richDoc('ALPHA again')]);
    $hash = hash('xxh128', DB::table('posts')->where('id', $post->getKey())->value('body'));
    $a->call('syncAutosave', ['body' => $hash]);
    expect(lastRichStatus($a, 'synced'))->toMatchArray(['stale' => ['body'], 'patches' => []]);
});

test('a private attachment whose src the state cast hides is neither refreshed nor stale', function () {
    $post = richPost(richDoc('intro'));
    $media = $post->addMediaFromString('image')->usingFileName('private.png')->toMediaCollection('content', 'public');
    $post->update(['body' => richDoc('intro', richImage($media->uuid, null))]);
    $a = Livewire::test(RichMergeEditPost::class, ['record' => $post->getKey()]);

    $a->call('syncAutosave');
    expect(lastRichStatus($a, 'synced'))->toBeNull();

    $post->update(['title' => 'Other column']);
    $a->call('syncAutosave');
    expect(lastRichStatus($a, 'synced'))->toMatchArray(['refreshed' => ['title' => 'Other column'], 'stale' => []]);

    $a->call('autosave', ['body' => ['base' => richDoc('intro', richImage($media->uuid, null))]]);
    expect($post->fresh()->getMedia('content'))->toHaveCount(1);
});

// --- HTML column and generic forms ------------------------------------------

test('an HTML rich column merges block by block', function () {
    $base = '<p>alpha</p><p>beta</p>';
    $post = PlainRichPost::create(['title' => 'Post', 'body' => $base]);
    $a = Livewire::test(RichMergeHtmlEditPost::class, ['record' => $post->getKey()]);
    $b = Livewire::test(RichMergeHtmlEditPost::class, ['record' => $post->getKey()]);

    // The form (and the browser) holds the document whatever the column stores.
    $bold = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'alpha', 'marks' => [['type' => 'bold']]]]];
    $list = ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'gamma']]]]]]];
    $a->set('data.body', richDoc($bold, 'beta'))->call('autosave', ['body' => ['base' => richDoc('alpha', 'beta')]]);
    $b->set('data.body', richDoc('alpha', 'beta', $list))->call('autosave', ['body' => ['base' => richDoc('alpha', 'beta')]]);

    expect($post->fresh()->body)->toBe('<p><strong>alpha</strong></p><p>beta</p><ul><li><p>gamma</p></li></ul>')
        ->and($b->get('data.body')['content'][0]['content'][0]['marks'][0]['type'])->toBe('bold')
        ->and($b->get('data.body')['content'][2]['type'])->toBe('bulletList');
});

test('a record-backed generic form merges a rich field like the edit page and can undo it', function () {
    $base = '<p>alpha</p><p>beta</p>';
    $post = PlainRichPost::create(['title' => 'Post', 'body' => $base]);
    $b = Livewire::test(RichMergeRecordForm::class, ['record' => $post]);

    $post->update(['body' => '<p>ALPHA</p><p>beta</p>']);
    $b->set('data.body', richDoc('alpha', 'BETA'))->call('autosave', ['body' => ['base' => richDoc('alpha', 'beta')]]);

    expect($post->fresh()->body)->toBe('<p>ALPHA</p><p>BETA</p>')
        ->and(richTexts($b->get('data.body')))->toBe(['ALPHA', 'BETA'])
        ->and(lastRichStatus($b, 'saved'))->toMatchArray(['conflicts' => []])
        ->and(richTexts(lastRichStatus($b, 'saved')['merged']['body']))->toBe(['ALPHA', 'BETA']);

    $post->update(['body' => '<p>elsewhere</p>']);
    $b->call('syncAutosave');
    expect(richTexts($b->get('data.body')))->toBe(['elsewhere']);

    $b->set('data.body', richDoc('elsewhere', 'more'))->call('autosave', ['body' => ['base' => richDoc('elsewhere')]]);
    expect($post->fresh()->body)->toBe('<p>elsewhere</p><p>more</p>');
    $b->call('undoAutosave');
    expect($post->fresh()->body)->toBe('<p>elsewhere</p>');
});

test('a generic form keeps a permanently contended rich field dirty and retries it next cycle', function () {
    config()->set('filament-autosave.merge_retries', 1);
    $base = '<p>alpha</p>';
    $post = PlainRichPost::create(['title' => 'Post', 'body' => $base]);
    $b = Livewire::test(RichMergeRecordForm::class, ['record' => $post]);
    $attempt = 0;

    $stop = contendRichColumn($post, 'body', function () use (&$attempt): string {
        return '<p>other '.(++$attempt).'</p>';
    }, null);

    $b->set('data.body', richDoc('alpha', 'mine'))->call('autosave', ['body' => ['base' => richDoc('alpha')]]);

    $status = lastRichStatus($b, 'validation');
    expect($post->fresh()->body)->toBe('<p>other 2</p>')
        ->and(richTexts($b->get('data.body')))->toBe(['alpha', 'mine'])
        ->and($status)->toMatchArray(['pending' => ['body']])
        ->and(richTexts($status['merged']['body']))->toBe(['other 2', 'mine'])
        ->and(richTexts($status['patches']['body']['theirs']))->toBe(['other 2'])
        ->and($status['patches']['body']['hash'])->toBe(hash('xxh128', '<p>other 2</p>'));

    $stop();
    $b->set('data.body', $status['merged']['body'])->call('autosave', ['body' => ['base' => $status['patches']['body']['theirs']]]);
    expect($post->fresh()->body)->toBe('<p>other 2</p><p>mine</p>');
});
