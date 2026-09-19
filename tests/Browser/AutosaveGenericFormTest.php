<?php

use Lenorix\FilamentAutosave\Tests\BrowserTestCase;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;

/**
 * A `HasAutosaveForForm` component hosted on a plain panel page, with the
 * indicator included by the component itself in `form` mode.
 *
 * The nested component's `autosave-status` event reaches its own indicator
 * because `dispatchAutosaveStatus()` scopes it with `->self()`; this test is
 * the regression guard for that path (the same markup on an Edit page went
 * through a different dispatch path and hid the bug for a while).
 */
test('a generic record form autosaves, survives reload, and undoes from the browser', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    $page = visit("/admin/generic-form/{$post->getKey()}")
        ->assertValue(BrowserTestCase::field('form.title'), 'Original');
    expect($this->currentStatus($page))->toBe('idle');

    $page->fill(BrowserTestCase::field('form.title'), 'Generic change');
    $this->waitForStatus($page, 'saved');
    expect($post->fresh()->title)->toBe('Generic change');

    visit("/admin/generic-form/{$post->getKey()}")
        ->assertValue(BrowserTestCase::field('form.title'), 'Generic change');

    $page->click(BrowserTestCase::action('undo'));
    $this->waitForStatus($page, 'undone');
    $this->waitForInputValue($page, BrowserTestCase::field('form.title'), 'Original');

    expect($post->fresh()->title)->toBe('Original');
    $this->assertNoBrowserErrors($page);
});

test('a server-side change to a generic form is autosaved without the user typing', function () {
    $post = Post::create(['title' => 'Hello World', 'slug' => 'original']);

    $page = visit("/admin/generic-form/{$post->getKey()}")
        ->assertValue(BrowserTestCase::field('form.slug'), 'original');

    $page->click('[data-fixture-action="generate-slug"]');

    $this->waitForInputValue($page, BrowserTestCase::field('form.slug'), 'hello-world');
    $this->waitForStatus($page, 'saved');

    expect($post->fresh()->slug)->toBe('hello-world');
    $this->assertNoBrowserErrors($page);
});
