<?php

use Lenorix\FilamentAutosave\Tests\BrowserTestCase;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;

/**
 * A `HasAutosaveForForm` component hosted on a plain panel page, with the
 * indicator included by the component itself in `form` mode.
 *
 * Blocked by the package, not by the harness. The save itself lands (the
 * column changes in the database) but the `autosave-status` event dispatched
 * by the nested component is only delivered to global `Livewire.on()`
 * listeners; the indicator's component-scoped `$wire.$on()` never fires, so
 * Alpine's `status` stays at `saving`, the badge reads "Saving your
 * changes...", Undo never appears, and every later edit is dropped by the
 * `status === saving` guard in `save()`. Verified with a fresh `$wire.$on`
 * attached from the test (nothing caught) against `Livewire.on` (caught
 * `status: saved`). The same markup inside a Filament page component (Edit
 * pages) works, so the difference is the nested-component dispatch path.
 * Candidate fixes live outside this stream: dispatch with `->self()` in
 * `dispatchAutosaveStatus()`, or listen via `Livewire.on` filtered by
 * `$wire.$id` in the controller. The assertions below are the contract.
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
