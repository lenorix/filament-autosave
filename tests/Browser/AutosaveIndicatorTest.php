<?php

use Lenorix\FilamentAutosave\Tests\BrowserTestCase;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;

/**
 * The Edit-page flow a user actually performs: type across two fields
 * inside one debounce window, watch the indicator settle, survive a reload,
 * undo, and end with a hidden indicator and a clean console.
 */
test('editing two fields within one debounce produces a single save that survives reload and can be undone', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $writes = 0;
    Post::updated(function () use (&$writes): void {
        $writes++;
    });

    $page = visit("/admin/posts/{$post->getKey()}/edit")
        ->assertValue(BrowserTestCase::field('form.title'), 'Original');

    // Two edits closer together than the debounce must collapse into one write.
    $page->fill(BrowserTestCase::field('form.title'), 'Changed in the browser');
    $page->fill(BrowserTestCase::field('form.slug'), 'changed-in-the-browser');
    $this->waitForStatus($page, 'saved');

    expect($writes)->toBe(1)
        ->and($post->fresh()->only(['title', 'slug']))
        ->toBe(['title' => 'Changed in the browser', 'slug' => 'changed-in-the-browser']);

    // A fresh page load shows the persisted values, with no draft or badge.
    $reloaded = visit("/admin/posts/{$post->getKey()}/edit")
        ->assertValue(BrowserTestCase::field('form.title'), 'Changed in the browser')
        ->assertValue(BrowserTestCase::field('form.slug'), 'changed-in-the-browser');
    expect($this->currentStatus($reloaded))->toBe('idle');

    // Undo on the original page restores both the inputs and the database.
    $page->click(BrowserTestCase::action('undo'));
    $this->waitForStatus($page, 'undone');
    $this->waitForInputValue($page, BrowserTestCase::field('form.title'), 'Original');
    $this->waitForInputValue($page, BrowserTestCase::field('form.slug'), 'original');

    expect($post->fresh()->only(['title', 'slug']))->toBe(['title' => 'Original', 'slug' => 'original']);

    // The undone badge fades back to idle and the indicator hides itself.
    $this->waitForStatus($page, 'idle');
    $page->assertMissing(BrowserTestCase::indicator('undone'));

    $this->assertNoBrowserErrors($page);
});

test('the indicator reports each phase through a stable data attribute', function () {
    $post = Post::create(['title' => 'Original']);

    $page = visit("/admin/posts/{$post->getKey()}/edit");
    expect($this->currentStatus($page))->toBe('idle');

    $page->fill(BrowserTestCase::field('form.title'), 'Typing');
    $this->waitForStatus($page, 'unsaved');
    $this->waitForStatus($page, 'saved');

    $page->assertPresent(BrowserTestCase::indicator('saved'))
        ->assertPresent(BrowserTestCase::action('undo'));

    $this->assertNoBrowserErrors($page);
});

/**
 * Alpine scope of the indicator, so a test can drive the controller the same
 * way the upload-finish and observed-hash paths do: by calling save() or the
 * wire's autosave() directly rather than through the keyboard.
 */
function indicatorScope(): string
{
    return 'Alpine.$data(document.querySelector("[data-autosave-status]"))';
}

test('a redundant autosave that comes back unchanged does not knock the saved badge down to idle', function () {
    $post = Post::create(['title' => 'Original']);

    $page = visit("/admin/posts/{$post->getKey()}/edit");
    $page->fill(BrowserTestCase::field('form.title'), 'Changed once');
    $this->waitForStatus($page, 'saved');

    // Same state again: the server answers "unchanged" (an idle status). This
    // is what happens after an upload finishes and the observed-hash watcher
    // fires a second save; it must not erase the badge the user is reading.
    $page->script('window.__redundantDone = false; '.indicatorScope().'.$wire.autosave().finally(() => { window.__redundantDone = true })');
    $this->waitUntil($page, 'window.__redundantDone === true', 'redundant autosave to settle');

    // Let the response's status event land, then check "saved" survived it.
    usleep(400_000);
    expect($this->currentStatus($page))->toBe('saved');

    $this->assertNoBrowserErrors($page);
});

test('two overlapping save triggers produce a single write', function () {
    $post = Post::create(['title' => 'Original']);
    $writes = 0;
    Post::updated(function () use (&$writes): void {
        $writes++;
    });

    $page = visit("/admin/posts/{$post->getKey()}/edit");

    // Set the field through the wire, then fire save() twice before the first
    // request can finish, as the upload-finish and hash watchers do.
    $page->script('(function () { const s = '.indicatorScope().'; s.$wire.set("data.title", "Overlapping", false); s.save(); s.save(); })()');
    $this->waitForStatus($page, 'saved');
    $this->waitUntil($page, indicatorScope().'.savePending === false', 'save in flight to finish');
    usleep(400_000);

    expect($writes)->toBe(1)
        ->and($post->fresh()->title)->toBe('Overlapping')
        ->and($this->currentStatus($page))->toBe('saved');

    $this->assertNoBrowserErrors($page);
});
