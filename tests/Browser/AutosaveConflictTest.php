<?php

use Lenorix\FilamentAutosave\Tests\BrowserTestCase;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;

test('undo is refused with a conflict badge when another editor changed the same column in between', function () {
    $post = Post::create(['title' => 'Original']);

    $page = visit("/admin/posts/{$post->getKey()}/edit");
    $page->fill(BrowserTestCase::field('form.title'), 'Mine');
    $this->waitForStatus($page, 'saved');
    expect($post->fresh()->title)->toBe('Mine');

    // Another editor wins the column before this tab undoes.
    $post->update(['title' => 'Someone else']);

    $page->click(BrowserTestCase::action('undo'));
    $this->waitForStatus($page, 'conflict');

    expect($post->fresh()->title)->toBe('Someone else');
    $page->assertValue(BrowserTestCase::field('form.title'), 'Mine');

    // The Undo target is gone; the badge is informational only.
    $page->assertMissing(BrowserTestCase::action('undo'));
    $this->assertNoBrowserErrors($page);
});

test('undo still works when another editor changed a different column', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    $page = visit("/admin/posts/{$post->getKey()}/edit");
    $page->fill(BrowserTestCase::field('form.title'), 'Mine');
    $this->waitForStatus($page, 'saved');

    $post->update(['slug' => 'changed-elsewhere']);

    $page->click(BrowserTestCase::action('undo'));
    $this->waitForStatus($page, 'undone');
    $this->waitForInputValue($page, BrowserTestCase::field('form.title'), 'Original');

    expect($post->fresh()->only(['title', 'slug']))->toBe(['title' => 'Original', 'slug' => 'changed-elsewhere']);
    $this->assertNoBrowserErrors($page);
});
