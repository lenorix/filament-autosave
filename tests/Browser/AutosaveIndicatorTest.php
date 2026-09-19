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
