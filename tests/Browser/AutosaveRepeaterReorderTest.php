<?php

use Lenorix\FilamentAutosave\Tests\BrowserTestCase;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\PostItem;

/**
 * Reordering through the Repeater's move buttons (drag-and-drop is too
 * fragile to drive): a server-side action that changes relationship state
 * without any typing, which the controller must still pick up.
 */
test('moving a relationship repeater row autosaves the new order and undo restores it', function () {
    $post = Post::create(['title' => 'Ordered']);
    $first = PostItem::create(['post_id' => $post->getKey(), 'label' => 'First', 'position' => 1]);
    $second = PostItem::create(['post_id' => $post->getKey(), 'label' => 'Second', 'position' => 2]);

    $page = visit("/admin/reorder-posts/{$post->getKey()}/edit")
        ->assertValue(BrowserTestCase::field("form.items.record-{$first->getKey()}.label"), 'First');

    // Both rows render a "Move down" button; only the first row's is enabled.
    $page->click('button[aria-label="Move down"]:not(.fi-disabled)');
    $this->waitForStatus($page, 'saved');

    expect($post->fresh()->items()->orderBy('position')->pluck('label')->all())->toBe(['Second', 'First'])
        ->and($first->fresh()->position)->toBe(2)
        ->and($second->fresh()->position)->toBe(1);

    $page->click(BrowserTestCase::action('undo'));
    $this->waitForStatus($page, 'undone');

    expect($post->fresh()->items()->orderBy('position')->pluck('label')->all())->toBe(['First', 'Second']);

    // The form reflects the restored order once the undo response lands.
    $this->waitUntil(
        $page,
        'Array.from(document.querySelectorAll("input[id^=\'form.items.\']")).map(i => i.value).join(",") === "First,Second"',
        'the repeater rows to show the original order',
    );
    $this->assertNoBrowserErrors($page);
});
