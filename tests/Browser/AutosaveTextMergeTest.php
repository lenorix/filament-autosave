<?php

use Lenorix\FilamentAutosave\AutosaveTextMerge;
use Lenorix\FilamentAutosave\Tests\BrowserTestCase;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;

/**
 * Two browsers on the same record, each typing in the same Textarea, with
 * nothing but polling between them. The body is listed as mergeable on
 * `BrowserMergeEditPost`; its 80-character limit keeps a long body dirty
 * (pending) so a poll has a local edit to merge the other editor's value
 * into.
 */
const MERGE_BODY = '[id="form.body"]';

function bodyOf(object $page): string
{
    return (string) $page->script('document.querySelector('.json_encode(MERGE_BODY).')?.value');
}

function selectionOf(object $page): array
{
    return (array) $page->script('(() => { const el = document.querySelector('.json_encode(MERGE_BODY).'); return [el.selectionStart, el.selectionEnd] })()');
}

/** JavaScript expression for the indicator's Alpine controller. */
function controller(): string
{
    return 'Alpine.$data(document.querySelector("[data-autosave-status]"))';
}

function placeCaret(object $page, int $start, ?int $end = null): void
{
    $page->script(sprintf(
        '(() => { const el = document.querySelector(%s); el.focus(); el.setSelectionRange(%d, %d); return true })()',
        json_encode(MERGE_BODY),
        $start,
        $end ?? $start,
    ));
}

/** Wait until a page's body input holds the value, yielding to the server meanwhile. */
function waitForBody(object $page, string $value, int $timeoutMs = 10_000): void
{
    $deadline = hrtime(true) + $timeoutMs * 1_000_000;

    do {
        if (bodyOf($page) === $value) {
            return;
        }

        usleep(100_000);
    } while (hrtime(true) < $deadline);

    throw new RuntimeException('Timed out waiting for the body to read '.json_encode($value).', got '.json_encode(bodyOf($page)));
}

beforeEach(function () {
    // Long enough that no poll lands between the steps of a test unless the
    // test lowers it: every remote change below must be merged on save,
    // not refilled beforehand.
    config(['filament-autosave.poll_interval' => 60_000]);
});

test('the browser builds the same patches and merges as the server', function () {
    $post = Post::create(['title' => 'Original', 'body' => 'alpha beta gamma']);
    $page = visit("/admin/merge-posts/{$post->getKey()}/edit");
    $engine = new AutosaveTextMerge;

    $pairs = [
        ['alpha beta gamma', 'ALPHA beta gamma'],
        ['alpha beta gamma', 'alpha beta gamma delta'],
        ['one two three four five six seven eight nine ten', 'one two THREE four five six seven eight NINE ten'],
        ['café 😀 naïve', 'café 😀😀 naïve!'],
        ['a, b; c/d?e:f@g&h=i+j$k!l*m\'n(o)p#q~r%s', 'a, b; c/d?e:f@g&h=i+j$k!l*m\'n(o)p#q~r%s and more'],
        ["line one\nline two\n", "line one\nLINE two\nline three\n"],
        [str_repeat('word ', 400).'end', str_repeat('word ', 200).'MIDDLE '.str_repeat('word ', 200).'end'],
    ];

    foreach ($pairs as [$before, $after]) {
        $patch = $page->script(sprintf('FilamentAutosaveMerge.engine.makePatch(%s, %s)', json_encode($before), json_encode($after)));

        expect($patch)->toBe($engine->makePatch($before, $after));
        // ...and the server applies that patch back to the same text.
        expect($engine->apply($before, (string) $patch)->value)->toBe($after);
    }

    $merge = $page->script('FilamentAutosaveMerge.engine.merge("alpha beta gamma", "alpha BETA gamma", "alpha Beta! gamma delta")');
    $expected = $engine->merge('alpha beta gamma', 'alpha BETA gamma', 'alpha Beta! gamma delta');

    expect($merge)->toBe(['value' => $expected->value, 'conflicts' => $expected->conflicts]);

    // Caret offsets are UTF-16 in the browser: an astral character before
    // the caret still maps to the right place.
    expect($page->script('FilamentAutosaveMerge.engine.mapOffset("😀 alpha beta", "😀 zero alpha beta", 8)'))->toBe(13);

    // The runtime is loaded once into the head, outside the component, so
    // a re-render never carries it again.
    expect($page->script('document.querySelectorAll("script[data-autosave-merge]").length'))->toBe(1)
        ->and($page->script('document.querySelector("script[data-autosave-merge]").closest("[wire\\\\:id]")'))->toBeNull();
    $this->assertNoBrowserErrors($page);
});

test('two editors changing different words of one textarea both end with the merged text', function () {
    $post = Post::create(['title' => 'Original', 'body' => 'alpha beta gamma']);

    $one = visit("/admin/merge-posts/{$post->getKey()}/edit")->assertValue(MERGE_BODY, 'alpha beta gamma');
    $two = visit("/admin/merge-posts/{$post->getKey()}/edit")->assertValue(MERGE_BODY, 'alpha beta gamma');

    $one->fill(MERGE_BODY, 'ALPHA beta gamma');
    $this->waitForStatus($one, 'saved');
    expect($post->fresh()->body)->toBe('ALPHA beta gamma');

    // The second editor started from the original and only touched the end.
    $two->fill(MERGE_BODY, 'alpha beta GAMMA');
    $this->waitForStatus($two, 'saved');

    expect($post->fresh()->body)->toBe('ALPHA beta GAMMA');
    waitForBody($two, 'ALPHA beta GAMMA');
    expect($this->currentStatus($two))->toBe('saved');

    // The first editor picks the merged text up on its next poll, untouched.
    $one->script(controller().'.poll()');
    waitForBody($one, 'ALPHA beta GAMMA');
    $this->waitForStatus($one, 'synced');

    // Nothing left to write on either side: another save is a no-op.
    $two->fill(BrowserTestCase::field('form.title'), 'Retitled');
    $this->waitForStatus($two, 'saved');
    expect($post->fresh()->only(['title', 'body']))->toBe(['title' => 'Retitled', 'body' => 'ALPHA beta GAMMA']);

    $this->assertNoBrowserErrors($one);
    $this->assertNoBrowserErrors($two);
});

test('an overlapping change keeps the last writer\'s words, shows the loser what was replaced, and lets them recover it', function () {
    $post = Post::create(['title' => 'Original', 'body' => 'alpha beta gamma']);

    $one = visit("/admin/merge-posts/{$post->getKey()}/edit")->assertValue(MERGE_BODY, 'alpha beta gamma');
    $two = visit("/admin/merge-posts/{$post->getKey()}/edit")->assertValue(MERGE_BODY, 'alpha beta gamma');

    $one->fill(MERGE_BODY, 'alpha BETA gamma');
    $this->waitForStatus($one, 'saved');

    $two->fill(MERGE_BODY, 'alpha Beta! gamma');
    $this->waitForStatus($two, 'saved');

    // Last writer wins in that range only; the first editor's word is reported.
    expect($post->fresh()->body)->toBe('alpha Beta! gamma');
    $this->waitUntil($two, 'document.querySelector("[data-autosave-conflicts]") !== null', 'the conflict callout');
    expect((string) $two->script('document.querySelector("[data-autosave-conflicts]").innerText'))->toContain('BETA');

    $two->click(BrowserTestCase::action('recover'));
    waitForBody($two, 'alpha BETA gamma');
    $this->waitForStatus($two, 'saved');
    $this->waitForDatabase($two, fn (): bool => $post->fresh()->body === 'alpha BETA gamma', 'the recovered text to land');

    $two->assertMissing('[data-autosave-conflicts]');
    $this->assertNoBrowserErrors($one);
    $this->assertNoBrowserErrors($two);
});

test('a poll merges the other editor\'s change into a dirty textarea around the caret', function () {
    config(['filament-autosave.poll_interval' => 500]);
    $post = Post::create(['title' => 'Original', 'body' => 'alpha beta gamma']);
    $page = visit("/admin/merge-posts/{$post->getKey()}/edit")->assertValue(MERGE_BODY, 'alpha beta gamma');

    // Too long for the field: the body stays dirty and pending while polling runs.
    $long = 'alpha beta gamma '.str_repeat('x', 70);
    $page->fill(MERGE_BODY, $long);
    $this->waitForStatus($page, 'validation');
    placeCaret($page, 5);

    Post::query()->whereKey($post->getKey())->update(['body' => 'zero alpha beta gamma']);

    waitForBody($page, 'zero '.$long);
    expect(selectionOf($page))->toBe([10, 10])
        ->and($this->currentStatus($page))->toBe('synced');

    // The merged text is still a local edit: it is neither re-saved by the
    // poll nor written to the database.
    expect($post->fresh()->body)->toBe('zero alpha beta gamma');

    // Trimming it back under the limit saves the merge of both edits.
    $page->fill(MERGE_BODY, 'zero alpha beta gamma mine');
    $this->waitForStatus($page, 'saved');
    expect($post->fresh()->body)->toBe('zero alpha beta gamma mine');

    $this->assertNoBrowserErrors($page);
});

test('a field contended through every retry adopts the merge against the latest value and stays dirty', function () {
    $post = Post::create(['title' => 'Original', 'body' => 'alpha beta gamma']);
    $page = visit("/admin/contended-posts/{$post->getKey()}/edit")->assertValue(MERGE_BODY, 'alpha beta gamma');

    Post::query()->whereKey($post->getKey())->update(['body' => 'alpha beta gamma delta']);

    $page->fill(MERGE_BODY, 'ALPHA beta gamma');
    $this->waitForStatus($page, 'validation');

    // The browser takes the merge computed against the latest value and
    // shows why nothing was written.
    waitForBody($page, 'ALPHA beta gamma delta');
    $this->waitUntil($page, 'document.querySelector("[data-autosave-conflict-reason=contended]") !== null', 'the contended callout');
    expect($post->fresh()->body)->toBe('alpha beta gamma delta');

    // The retry sends a patch from the new base; the field is still dirty.
    $this->waitUntil($page, controller().'.mergeSync.base("body") === "alpha beta gamma delta"', 'the base to move to the latest value');
    expect($page->script(controller().'.mergeSync.patches({ body: document.querySelector('.json_encode(MERGE_BODY).').value })'))->toHaveKey('body');

    $this->assertNoBrowserErrors($page);
});

test('text typed while a save is in flight survives the merge that comes back', function () {
    $post = Post::create(['title' => 'Original', 'body' => 'alpha beta gamma']);

    $one = visit("/admin/merge-posts/{$post->getKey()}/edit")->assertValue(MERGE_BODY, 'alpha beta gamma');
    $two = visit("/admin/merge-posts/{$post->getKey()}/edit")->assertValue(MERGE_BODY, 'alpha beta gamma');

    $one->fill(MERGE_BODY, 'ALPHA beta gamma');
    $this->waitForStatus($one, 'saved');

    $two->fill(MERGE_BODY, 'alpha beta gamma mine');
    $this->waitForStatus($two, 'unsaved');

    // Start the save by hand and keep typing before the reply arrives.
    $two->script(sprintf(<<<'JS'
        (() => {
            const el = document.querySelector(%s)
            const flight = %s.save()
            el.focus()
            el.setSelectionRange(el.value.length, el.value.length)
            el.value += ' more'
            el.setSelectionRange(el.value.length, el.value.length)
            el.dispatchEvent(new Event('input', { bubbles: true }))
            return flight
        })()
        JS, json_encode(MERGE_BODY), controller()));

    // The reply merges the other editor's start into the field without
    // touching the word typed meanwhile, and the follow-up save lands it.
    waitForBody($two, 'ALPHA beta gamma mine more');
    $this->waitForDatabase($two, fn (): bool => $post->fresh()->body === 'ALPHA beta gamma mine more', 'the in-flight word to land');
    expect(selectionOf($two))->toBe([26, 26]);

    $two->assertMissing('[data-autosave-conflicts]');
    $this->assertNoBrowserErrors($two);
});

test('a generic record form merges from the browser too, with its base advancing after each save', function () {
    $post = Post::create(['title' => 'Hello World', 'slug' => 'original']);
    $title = BrowserTestCase::field('form.title');

    $page = visit("/admin/generic-form/{$post->getKey()}")->assertValue($title, 'Hello World');

    $page->fill($title, 'Hello Big World');
    $this->waitForStatus($page, 'saved');

    // Someone else changes the first word; this form changes the last one,
    // and its patch must start from its own last save, not from mount.
    Post::query()->whereKey($post->getKey())->update(['title' => 'HELLO Big World']);
    $page->fill($title, 'Hello Big Universe');
    $this->waitForStatus($page, 'saved');

    expect($post->fresh()->title)->toBe('HELLO Big Universe');
    $this->waitForInputValue($page, $title, 'HELLO Big Universe');
    $page->assertMissing('[data-autosave-conflicts]');
    $this->assertNoBrowserErrors($page);
});

test('the base follows consecutive saves and an undo, so later patches apply cleanly', function () {
    $post = Post::create(['title' => 'Original', 'body' => 'alpha beta gamma']);
    $page = visit("/admin/merge-posts/{$post->getKey()}/edit")->assertValue(MERGE_BODY, 'alpha beta gamma');

    $page->fill(MERGE_BODY, 'ALPHA beta gamma');
    $this->waitForStatus($page, 'saved');
    $page->fill(MERGE_BODY, 'ALPHA beta GAMMA');
    $this->waitForStatus($page, 'saved');
    expect($post->fresh()->body)->toBe('ALPHA beta GAMMA');

    $page->click(BrowserTestCase::action('undo'));
    $this->waitForStatus($page, 'undone');
    waitForBody($page, 'ALPHA beta gamma');
    $this->waitUntil($page, controller().'.mergeSync.base("body") === "ALPHA beta gamma"', 'the base to follow the undo');

    // Another editor changes the start after the undo; this browser's next
    // patch must be relative to the undone value, not the one before it.
    Post::query()->whereKey($post->getKey())->update(['body' => 'alpha beta gamma']);
    $page->fill(MERGE_BODY, 'ALPHA beta gamma delta');
    $this->waitForStatus($page, 'saved');

    expect($post->fresh()->body)->toBe('alpha beta gamma delta');
    waitForBody($page, 'alpha beta gamma delta');
    $page->assertMissing('[data-autosave-conflicts]');
    $this->assertNoBrowserErrors($page);
});
