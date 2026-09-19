<?php

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;

/**
 * Poll the page until a JavaScript expression is truthy; bounded, no sleeps
 * sized to the poll interval.
 */
function pollPageUntil(object $page, string $expression, int $timeoutMs = 10_000): object
{
    $deadline = hrtime(true) + $timeoutMs * 1_000_000;

    do {
        if ($page->script($expression)) {
            return $page;
        }

        usleep(100_000);
    } while (hrtime(true) < $deadline);

    throw new RuntimeException("Timed out after {$timeoutMs}ms waiting for: {$expression}");
}

function inputValueIs(string $id, string $value): string
{
    return sprintf('document.getElementById(%s)?.value === %s', json_encode($id), json_encode($value));
}

function elementExists(string $selector): string
{
    return sprintf('document.querySelector(%s) !== null', json_encode($selector));
}

function elementTextIncludes(string $selector, string $text): string
{
    return sprintf('(document.querySelector(%s)?.innerText || "").includes(%s)', json_encode($selector), json_encode($text));
}

beforeEach(function () {
    // Short interval so the test waits on real polls, not on a long timer.
    config(['filament-autosave.poll_interval' => 500]);
});

test('another editor\'s change to an untouched field appears without saving, while a field being edited is kept and flagged', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    $page = visit("/admin/posts/{$post->getKey()}/edit")
        ->assertValue('[id="form.slug"]', 'original');

    // Another editor changes a field this user has not touched.
    Post::query()->whereKey($post->getKey())->update(['slug' => 'changed-elsewhere']);

    pollPageUntil($page, inputValueIs('form.slug', 'changed-elsewhere'));
    pollPageUntil($page, elementExists('[data-autosave-synced]'));

    // Clearing the required title keeps it dirty: validation blocks the
    // autosave, so the field stays a local edit while polling resumes. Wait
    // for the validation badge so the server has definitely seen the edit.
    $page->clear('[id="form.title"]');
    pollPageUntil($page, inputValueIs('form.title', ''));
    pollPageUntil($page, sprintf('document.body.innerText.includes(%s)', json_encode(__('filament-autosave::autosave.validation'))));

    Post::query()->whereKey($post->getKey())->update(['title' => 'Title changed elsewhere', 'slug' => 'slug-two']);

    pollPageUntil($page, inputValueIs('form.slug', 'slug-two'));
    pollPageUntil($page, elementTextIncludes('[data-autosave-stale]', 'title'));

    $page->assertValue('[id="form.title"]', '');

    expect($post->fresh()->title)->toBe('Title changed elsewhere');
});
