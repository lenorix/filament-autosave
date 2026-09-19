<?php

use Lenorix\FilamentAutosave\Tests\BrowserTestCase;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;

test('clearing a required field lists it as pending while a sibling change is still saved', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    $page = visit("/admin/posts/{$post->getKey()}/edit");
    $page->fill(BrowserTestCase::field('form.title'), '');
    $page->fill(BrowserTestCase::field('form.slug'), 'still-saved');
    $this->waitForStatus($page, 'validation');

    $this->waitUntil(
        $page,
        'document.querySelector(\'[data-autosave-status="validation"]\')?.innerText.includes("title")',
        'the validation badge to list the pending title field',
    );

    expect($post->fresh()->only(['title', 'slug']))->toBe(['title' => 'Original', 'slug' => 'still-saved']);

    // Filling the field back in clears the warning on the next save.
    $page->fill(BrowserTestCase::field('form.title'), 'Fixed');
    $this->waitForStatus($page, 'saved');
    $page->assertMissing(BrowserTestCase::indicator('validation'));

    expect($post->fresh()->title)->toBe('Fixed');
    $this->assertNoBrowserErrors($page);
});
