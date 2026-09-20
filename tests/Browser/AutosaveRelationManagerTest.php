<?php

use Lenorix\FilamentAutosave\Tests\BrowserTestCase;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Comment;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;

/**
 * A whole resource that autosaves top to bottom: the Edit page with
 * `HasAutosave`, and a relation manager's edit modal with
 * `HasAutosaveForRelationManager` and no other wiring — including its own
 * indicator, which the plugin injects into the action modal automatically.
 *
 * The page carries two indicators (the Edit page's own, and the modal's), so
 * status checks here are scoped to `.fi-modal` — `BrowserTestCase`'s own
 * helpers assume a single indicator per page, which every other autosave
 * surface in this suite has. The relation manager also loads lazily, so this
 * test waits for its table (the comment's own body text) first.
 */
function openCommentModal(object $page): object
{
    $page->click("[data-testid='edit-comment']");

    return $page;
}

function modalStatus(object $page): string
{
    return (string) ($page->script('document.querySelector(".fi-modal [data-autosave-status]")?.dataset.autosaveStatus') ?? 'idle');
}

test('a relation manager edit modal autosaves and undoes with no wiring at all', function (string $application) {
    $post = Post::create(['title' => $application]);
    $comment = Comment::create([
        'body' => 'Original comment',
        'commentable_type' => $post->getMorphClass(),
        'commentable_id' => $post->getKey(),
    ]);

    $page = visit("/admin/relation-manager-posts/{$post->getKey()}/edit");

    $this->waitUntil(
        $page,
        'document.body.innerText.includes("Original comment")',
        'the relation manager table to finish loading',
    );

    openCommentModal($page);
    $this->waitUntil(
        $page,
        'document.body.innerText.includes("Edit comment")',
        'the edit modal to open',
    );

    // The modal has its own indicator, injected with no view for the
    // consumer to touch; it starts idle like any other autosave surface.
    expect(modalStatus($page))->toBe('idle');

    $page->fill(BrowserTestCase::field('mountedActionSchema0.body'), 'Updated in the modal');
    $this->waitUntil(
        $page,
        'document.querySelector(".fi-modal [data-autosave-status]")?.dataset.autosaveStatus === "saved"',
        'modal indicator status [saved]',
    );

    $this->waitForDatabase(
        $page,
        fn (): bool => $comment->fresh()->body === 'Updated in the modal',
        'the modal edit to land on the row',
    );

    $page->click('.fi-modal '.BrowserTestCase::action('undo'));
    $this->waitUntil(
        $page,
        'document.querySelector(".fi-modal [data-autosave-status]")?.dataset.autosaveStatus === "undone"',
        'modal indicator status [undone]',
    );

    $this->waitForDatabase(
        $page,
        fn (): bool => $comment->fresh()->body === 'Original comment',
        'Undo to restore the row from the modal',
    );

    $this->assertNoBrowserErrors($page);
})->with(['first application', 'fresh application']);
