<?php

use Lenorix\FilamentAutosave\Tests\BrowserTestCase;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;

/**
 * The controller under the timings a real network produces: a poll reply
 * landing while a save is in flight, a tab closed inside the debounce, and
 * a server reply that never carries a status.
 *
 * Every JavaScript status the indicator goes through is recorded from a
 * MutationObserver, so the assertions cover the transient badges and not
 * only the settled one.
 */
function installStatusLog(object $page): void
{
    $page->script(<<<'JS'
        window.__statuses = []
        const record = () => {
            const status = document.querySelector('[data-autosave-status]')?.dataset.autosaveStatus ?? 'idle'
            if (window.__statuses[window.__statuses.length - 1] !== status) {
                window.__statuses.push(status)
            }
        }
        new MutationObserver(record).observe(document.body, { subtree: true, attributes: true, childList: true, attributeFilter: ['data-autosave-status'] })
        record()
    JS);
}

/**
 * Hold Livewire replies for the given methods in the browser for a while,
 * so two requests overlap the way they do on a slow connection. Also
 * exposes which of those methods are in flight right now.
 */
function delayWireReplies(object $page, array $delaysMs): void
{
    $page->script(sprintf(<<<'JS'
        const delays = %s
        window.__inFlight = {}
        const original = window.fetch
        window.fetch = async function (...args) {
            const body = String(args[1]?.body || '')
            const method = Object.keys(delays).find((m) => body.includes('"method":"' + m + '"'))
            if (method) window.__inFlight[method] = (window.__inFlight[method] || 0) + 1
            try {
                const response = await original.apply(this, args)
                if (method) await new Promise((resolve) => setTimeout(resolve, delays[method]))
                return response
            } finally {
                if (method) window.__inFlight[method]--
            }
        }
    JS, json_encode($delaysMs)));
}

test('a poll reply landing during an in-flight save neither demotes the badge nor loses the remote change', function () {
    config(['filament-autosave.poll_interval' => 500]);
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    $page = visit("/admin/posts/{$post->getKey()}/edit")
        ->assertValue(BrowserTestCase::field('form.slug'), 'original');
    installStatusLog($page);
    // Poll replies take 2 s to land, saves 3 s: a save fired 1.5 s into a
    // poll is still running when that poll's reply arrives.
    delayWireReplies($page, ['syncAutosave' => 2000, 'autosave' => 3000]);

    Post::query()->whereKey($post->getKey())->update(['slug' => 'changed-elsewhere']);

    $this->waitUntil($page, '(window.__inFlight.syncAutosave || 0) > 0', 'a poll to be in flight');
    $page->fill(BrowserTestCase::field('form.title'), 'Typed during a poll');

    $this->waitForStatus($page, 'saved');
    $this->waitForInputValue($page, BrowserTestCase::field('form.slug'), 'changed-elsewhere');

    $statuses = $page->script('window.__statuses');
    $firstSaving = array_search('saving', $statuses, true);
    expect($firstSaving)->not->toBeFalse()
        ->and(array_slice($statuses, $firstSaving))->not->toContain('unsaved')
        ->and($post->fresh()->only(['title', 'slug']))->toBe(['title' => 'Typed during a poll', 'slug' => 'changed-elsewhere']);

    $this->assertNoBrowserErrors($page);
});

test('switching the tab away inside the debounce window saves right away', function () {
    // A long debounce: only a flush explains a write within the next 2 s.
    config(['filament-autosave.debounce' => 6000]);
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    $page = visit("/admin/posts/{$post->getKey()}/edit")
        ->assertValue(BrowserTestCase::field('form.title'), 'Original');
    $page->fill(BrowserTestCase::field('form.title'), 'Typed before switching tabs');
    $this->waitForStatus($page, 'unsaved');

    // Playwright cannot background a tab; the document reports hidden and
    // fires the event exactly as the browser would.
    $page->script(<<<'JS'
        Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' })
        document.dispatchEvent(new Event('visibilitychange'))
    JS);

    $this->waitForDatabase($page, fn (): bool => $post->fresh()->title === 'Typed before switching tabs', 'the flushed title', timeoutMs: 2_000);
    $this->assertNoBrowserErrors($page);
});

test('leaving the page inside the debounce window still lands the last edit', function () {
    config(['filament-autosave.debounce' => 6000]);
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    $page = visit("/admin/posts/{$post->getKey()}/edit")
        ->assertValue(BrowserTestCase::field('form.title'), 'Original');
    $page->fill(BrowserTestCase::field('form.title'), 'Typed before leaving');
    $this->waitForStatus($page, 'unsaved');

    $page->navigate('/admin/posts');

    $this->waitForDatabase($page, fn (): bool => $post->fresh()->title === 'Typed before leaving', 'the title flushed on beforeunload', timeoutMs: 3_000);
});
