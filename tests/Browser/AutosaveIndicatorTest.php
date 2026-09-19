<?php

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;

/**
 * Poll the page until a JavaScript expression is truthy.
 *
 * The plugin's assertions check immediately, so anything that appears after
 * the autosave debounce needs an explicit bounded poll rather than a sleep
 * sized to the debounce. `visit()` hands back an awaitable proxy rather than
 * the Webpage itself, so the page is duck-typed on `script()`.
 */
function waitUntilPage(object $page, string $expression, int $timeoutMs = 10_000): object
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

function pageShowsText(string $text): string
{
    return sprintf('document.body.innerText.includes(%s)', json_encode($text));
}

function inputHasValue(string $id, string $value): string
{
    return sprintf(
        'document.getElementById(%s)?.value === %s',
        json_encode($id),
        json_encode($value),
    );
}

test('typing autosaves after the debounce, shows the saved badge, and undo restores the input', function () {
    $post = Post::create(['title' => 'Original']);

    $page = visit("/admin/posts/{$post->getKey()}/edit")
        ->assertValue('[id="form.title"]', 'Original')
        ->type('[id="form.title"]', 'Changed in the browser');

    waitUntilPage($page, pageShowsText(__('filament-autosave::autosave.saved_at')));
    waitUntilPage($page, pageShowsText(__('filament-autosave::autosave.undo')));

    expect($post->fresh()->title)->toBe('Changed in the browser');

    $page->press(__('filament-autosave::autosave.undo'));

    waitUntilPage($page, pageShowsText(__('filament-autosave::autosave.undone')));
    waitUntilPage($page, inputHasValue('form.title', 'Original'));

    $page->assertValue('[id="form.title"]', 'Original');

    expect($post->fresh()->title)->toBe('Original');
});
