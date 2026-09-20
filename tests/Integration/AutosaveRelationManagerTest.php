<?php

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Comment;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\PostItem;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\RelationManager\CommentsRelationManager;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\RelationManager\ItemsRelationManager;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Relationship\RelationshipEditPost;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * `HasAutosaveForRelationManager` is `HasAutosaveForForm` with every default a
 * relation manager can infer already filled in: the mounted action's schema
 * and state path, a scope built from owner + relationship + action + row, and
 * the per-modal state that has to be re-armed each time a modal opens.
 *
 * Actions are mounted through instance calls (`mountTableAction`/`mountAction`
 * on `$page->instance()`), not the `->mountAction(TestAction::make(...))`
 * testing macro: chaining that macro's own render round-trip after another
 * test already ran a full action cycle hits a pre-existing Livewire/Testbench
 * rendering quirk unrelated to this trait (reproduced with the package's own
 * already-shipped `AutosaveCommentsRelationManager` fixture, so it is an
 * environment issue, not a regression). Direct instance calls are the pattern
 * `AutosaveOtherContextsTest.php` already uses at scale, and a real browser
 * driving the real endpoint covers the full request/dehydrate cycle these
 * unit-level tests skip (`tests/Browser/AutosaveRelationManagerTest.php`).
 *
 * Every mount/unmount here is followed by an explicit
 * `dehydrateHasAutosaveForRelationManager()` call: a real browser gets this
 * for free once per Livewire request (Livewire always dehydrates at the end
 * of one), but a direct instance call bypasses that request boundary, so the
 * per-modal baseline (field hashes, poll interval, Undo) has to be re-armed
 * by hand to reflect what the next real request would see.
 */
function relationManager(string $class, Post $owner): Testable
{
    return Livewire::test($class, [
        'ownerRecord' => $owner,
        'pageClass' => RelationshipEditPost::class,
    ]);
}

function commentFor(Post $post, string $body = 'Original comment'): Comment
{
    return Comment::create([
        'body' => $body,
        'commentable_type' => $post->getMorphClass(),
        'commentable_id' => $post->getKey(),
    ]);
}

test('an edit modal autosaves its row with no wiring at all', function () {
    $post = Post::create(['title' => 'Post']);
    $comment = commentFor($post);

    $page = relationManager(CommentsRelationManager::class, $post);
    $instance = $page->instance();
    $instance->mountTableAction('edit', (string) $comment->getKey());
    $instance->dehydrateHasAutosaveForRelationManager();
    $instance->fillMountedAction(['body' => 'Updated comment']);
    $written = $instance->flushAutosave();

    expect($written)->toBeTrue()
        ->and($comment->refresh()->body)->toBe('Updated comment');
});

test('the mounted modal becomes the autosave form and its state path', function () {
    $post = Post::create(['title' => 'Post']);
    $comment = commentFor($post);

    $page = relationManager(CommentsRelationManager::class, $post);

    expect($page->get('autosaveDataPath'))->toBe('data');

    $page->instance()->mountTableAction('edit', (string) $comment->getKey());
    $page->instance()->dehydrateHasAutosaveForRelationManager();

    expect($page->instance()->autosaveDataPath)->toBe('mountedActions.0.data');
});

test('opening a modal re-arms the per-modal autosave state', function () {
    $post = Post::create(['title' => 'Post']);
    $comment = commentFor($post);

    $page = relationManager(CommentsRelationManager::class, $post);
    $instance = $page->instance();

    // Nothing is open yet: no field baseline and nothing to poll for.
    expect($instance->autosaveFieldHashes)->toBe([])
        ->and($instance->autosavePollMs)->toBe(0);

    $instance->mountTableAction('edit', (string) $comment->getKey());
    $instance->dehydrateHasAutosaveForRelationManager();

    // The modal's own values are the baseline, and the row it edits is a
    // record, so this modal polls for other editors like any record form.
    expect($instance->autosaveFieldHashes)->toHaveKey('body')
        ->and($instance->autosavePollMs)->toBe(5000);
});

test('a modal that changed nothing is not written again', function () {
    $post = Post::create(['title' => 'Post']);
    $comment = commentFor($post);

    $page = relationManager(CommentsRelationManager::class, $post);
    $instance = $page->instance();
    $instance->mountTableAction('edit', (string) $comment->getKey());
    $instance->dehydrateHasAutosaveForRelationManager();

    expect($instance->flushAutosave())->toBeFalse();
});

test('an autosaved modal can be undone', function () {
    $post = Post::create(['title' => 'Post']);
    $comment = commentFor($post);

    $page = relationManager(CommentsRelationManager::class, $post);
    $instance = $page->instance();
    $instance->mountTableAction('edit', (string) $comment->getKey());
    $instance->dehydrateHasAutosaveForRelationManager();
    $instance->fillMountedAction(['body' => 'Updated comment']);
    $instance->autosave();

    expect($instance->autosaveCanUndo)->toBeTrue();

    $instance->undoAutosave();

    expect($comment->refresh()->body)->toBe('Original comment')
        ->and($instance->autosaveCanUndo)->toBeFalse();
});

test('each row of a relation manager gets its own autosave scope', function () {
    $post = Post::create(['title' => 'Post']);
    $first = commentFor($post, 'First');
    $second = commentFor($post, 'Second');

    $page = relationManager(CommentsRelationManager::class, $post);
    $instance = $page->instance();
    $contexts = [];

    foreach ([$first, $second] as $comment) {
        $instance->mountTableAction('edit', (string) $comment->getKey());
        $contexts[] = invade($instance)->getAutosaveFormContext();
        $instance->unmountAction();
    }

    expect($contexts[0])->not->toBe($contexts[1]);
});

test('two relation managers of the same owner never share a scope', function () {
    $post = Post::create(['title' => 'Post']);
    $comment = commentFor($post);
    $item = PostItem::create(['post_id' => $post->getKey(), 'label' => 'Item', 'position' => 1]);

    $comments = relationManager(CommentsRelationManager::class, $post)->instance();
    $comments->mountTableAction('edit', (string) $comment->getKey());

    $items = relationManager(ItemsRelationManager::class, $post)->instance();
    $items->mountTableAction('edit', (string) $item->getKey());

    expect(invade($comments)->getAutosaveFormContext())
        ->not->toBe(invade($items)->getAutosaveFormContext())
        ->and(invade($comments)->getAutosaveFormContext())
        ->toContain('relation:comments');
});

test('a create modal keeps a draft until the row is created', function () {
    $post = Post::create(['title' => 'Post']);

    $page = relationManager(CommentsRelationManager::class, $post);
    $instance = $page->instance();
    $instance->mountTableAction('create');
    $instance->dehydrateHasAutosaveForRelationManager();
    $instance->fillMountedAction(['body' => 'Drafted comment']);
    $instance->autosave();

    expect($instance->autosaveHasDraft)->toBeTrue()
        ->and(Comment::count())->toBe(0);

    $instance->callMountedAction();

    expect(Comment::where('body', 'Drafted comment')->count())->toBe(1)
        ->and($instance->autosaveHasDraft)->toBeFalse();
});

test('closing a modal leaves nothing to autosave or undo', function () {
    $post = Post::create(['title' => 'Post']);
    $comment = commentFor($post);

    $page = relationManager(CommentsRelationManager::class, $post);
    $instance = $page->instance();
    $instance->mountTableAction('edit', (string) $comment->getKey());
    $instance->dehydrateHasAutosaveForRelationManager();
    $instance->fillMountedAction(['body' => 'Updated comment']);
    $instance->autosave();

    expect($instance->autosaveCanUndo)->toBeTrue();

    $instance->unmountAction();
    $instance->dehydrateHasAutosaveForRelationManager();

    expect($instance->autosaveCanUndo)->toBeFalse()
        ->and($instance->autosavePollMs)->toBe(0)
        ->and($instance->autosaveFieldHashes)->toBe([]);

    expect($instance->flushAutosave())->toBeFalse();
});
