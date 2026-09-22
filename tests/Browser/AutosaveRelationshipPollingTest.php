<?php

use Lenorix\FilamentAutosave\Tests\BrowserTestCase;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\PollItem;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\PollPost;

/**
 * Real-browser coverage for polling relationship, self-referential and
 * through-relation fields: the "browser polls racing with typing, autosave
 * and Undo" item tests/Integration/RELATION_POLLING.md still listed as
 * outstanding. The underlying detection/batching is pinned at the
 * Integration level (AutosaveNestedRelationshipsTest,
 * AutosaveNestedRelationshipCycleAndThroughTest, AutosavePollRelationshipsTest);
 * these confirm it survives a real Livewire request/response cycle, a real
 * debounce and a real poll timer.
 *
 * `syncAutosave()` calls `skipRender()` unconditionally (see
 * src/HasAutosaveBase.php:2250) to avoid a full Filament re-render, and
 * comments there and re-hydrating nested Repeater rows, on every idle poll
 * tick. That means a *new* repeater row discovered by polling updates
 * `$wire.data.items` but never reaches the DOM: there is no client-side
 * patching for row insertion the way there is for scalar column merges
 * (FilamentAutosaveMerge.apply.toInput). Confirmed directly: after a
 * remote row is added and the indicator settles on "synced", the
 * underlying wire state holds both rows but only the original row's input
 * is rendered.
 *
 * The `composer test:browser` suite runs with --fail-on-skipped, so the two
 * scenarios that would assert a brand new row becoming visible ("a
 * relationship row another editor added appears... without saving" and its
 * self-referential/grandchild variant) are omitted here rather than shipped
 * as skipped tests: as currently implemented, that DOM update never
 * happens, so a test asserting it would only ever be a permanent skip or a
 * permanent failure. This is a product design question for the maintainer
 * (whether `syncAutosave()` should render when a relation changed, and how
 * to avoid reintroducing the per-row Repeater hydration cost the skipRender
 * call at that line guards against), not something this test file's scope
 * can fix.
 */
beforeEach(function () {
    // Short interval so the tests wait on real polls, not a long timer.
    config(['filament-autosave.poll_interval' => 500]);
});

test('a row this tab is editing stays local and is reported stale when another editor changes the same row', function () {
    $post = PollPost::create(['title' => 'Original']);
    $item = $post->items()->create(['label' => 'First', 'position' => 1]);

    $page = visit("/admin/browser-poll-relations-posts/{$post->getKey()}/edit")
        ->assertValue(BrowserTestCase::field("form.items.record-{$item->getKey()}.label"), 'First');

    // Blanking a required row field keeps it dirty forever: validation
    // blocks the write, so the acknowledged hash never advances. The same
    // technique tests/Browser/AutosavePollingTest.php already relies on.
    $page->clear(BrowserTestCase::field("form.items.record-{$item->getKey()}.label"));
    $this->waitForInputValue($page, BrowserTestCase::field("form.items.record-{$item->getKey()}.label"), '');

    // Wait for the server to have actually run the debounced autosave
    // attempt and reported validation, not just for the input's own value:
    // otherwise the remote update below can race ahead of it, and the poll
    // sometimes sees the field as not-yet-dirty on the server side.
    $this->waitUntil(
        $page,
        sprintf('document.body.innerText.includes(%s)', json_encode(__('filament-autosave::autosave.validation'))),
        'the validation status to be reported',
    );

    PollItem::query()
        ->whereKey($item->getKey())
        ->update(['label' => 'Changed elsewhere']);

    $this->waitUntil(
        $page,
        sprintf('(document.querySelector("[data-autosave-stale]")?.innerText || "").includes(%s)', json_encode('items')),
        'the stale indicator to list the items field',
        20_000,
    );

    // The local (blank) edit was preserved, not overwritten by the remote row.
    $page->assertValue(BrowserTestCase::field("form.items.record-{$item->getKey()}.label"), '');
    expect($item->fresh()->label)->toBe('Changed elsewhere');

    $this->assertNoBrowserErrors($page);
});

test('undo after a relationship save is not resurrected by a poll reply landing around it', function () {
    $post = PollPost::create(['title' => 'Original']);
    $item = $post->items()->create(['label' => 'Before', 'position' => 1]);

    $page = visit("/admin/browser-poll-relations-posts/{$post->getKey()}/edit")
        ->assertValue(BrowserTestCase::field("form.items.record-{$item->getKey()}.label"), 'Before');

    $page->fill(BrowserTestCase::field("form.items.record-{$item->getKey()}.label"), 'After');
    $this->waitForStatus($page, 'saved');
    expect($item->fresh()->label)->toBe('After');

    // Hold the next poll's reply so it is still in flight when Undo runs,
    // then let it land after: the row must end up (and stay) reverted
    // either way, mirroring the poll-vs-save race already pinned for a
    // plain column in AutosaveControllerResilienceTest.
    $page->script(sprintf(<<<'JS'
        window.__inFlight = {}
        const original = window.fetch
        window.fetch = async function (...args) {
            const body = String(args[1]?.body || '')
            const isSync = body.includes('"method":"syncAutosave"')
            if (isSync) window.__inFlight.syncAutosave = (window.__inFlight.syncAutosave || 0) + 1
            try {
                const response = await original.apply(this, args)
                if (isSync) await new Promise((resolve) => setTimeout(resolve, %d))
                return response
            } finally {
                if (isSync) window.__inFlight.syncAutosave--
            }
        }
    JS, 1500));

    $this->waitUntil($page, '(window.__inFlight.syncAutosave || 0) > 0', 'a poll to be in flight');
    $page->click(BrowserTestCase::action('undo'));
    $this->waitForStatus($page, 'undone');

    expect($item->fresh()->label)->toBe('Before');

    // Let the delayed poll reply land, then confirm the undone value held.
    $this->waitUntil($page, '(window.__inFlight.syncAutosave || 0) === 0', 'the delayed poll reply to land', timeoutMs: 5_000);
    $page->assertValue(BrowserTestCase::field("form.items.record-{$item->getKey()}.label"), 'Before');
    expect($item->fresh()->label)->toBe('Before');

    $this->assertNoBrowserErrors($page);
});
