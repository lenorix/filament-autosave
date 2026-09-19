<?php

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;

/**
 * Bounded poll against the live page; the plugin's assertions do not retry.
 */
function themeWaitUntil(object $page, string $expression, int $timeoutMs = 10_000): object
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

/** Empty the required title and change slug so the cycle ends in `validation` with a pending list. */
function themeForceValidationState(object $page): object
{
    $page->fill('[id="form.title"]', '')->type('[id="form.slug"]', '-themed');

    return themeWaitUntil(
        $page,
        sprintf('document.body.innerText.includes(%s)', json_encode(__('filament-autosave::autosave.validation'))),
    );
}

const THEME_NOTE = '.fi-autosave-indicator .fi-autosave-note';
const THEME_BADGE = '.fi-autosave-indicator .fi-badge';

function themeComputed(object $page, string $selector, string $property): string
{
    return (string) $page->script(sprintf(
        '(() => { const el = document.querySelector(%s); return el ? getComputedStyle(el)[%s] : ""; })()',
        json_encode($selector),
        json_encode($property),
    ));
}

test('the indicator helper text is styled from the package stylesheet in light mode', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    $page = visit("/admin/posts/{$post->getKey()}/edit");
    themeForceValidationState($page);
    themeWaitUntil($page, 'document.querySelector('.json_encode(THEME_NOTE).') !== null');

    $fontSize = (float) themeComputed($page, THEME_NOTE, 'fontSize');
    $color = themeComputed($page, THEME_NOTE, 'color');

    expect($fontSize)->toBeGreaterThan(0)->toBeLessThan(14.0)
        ->and($color)->not->toBe('rgb(0, 0, 0)')->not->toBe('');

    expect($page->script('document.documentElement.classList.contains("dark")'))->toBeFalse();
});

test('the indicator helper text and badge follow the dark theme', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    $page = visit("/admin/posts/{$post->getKey()}/edit");
    themeForceValidationState($page);
    themeWaitUntil($page, 'document.querySelector('.json_encode(THEME_NOTE).') !== null');
    $lightColor = themeComputed($page, THEME_NOTE, 'color');

    // Filament's own theme switcher toggles the `dark` class on <html> at
    // runtime, which is exactly what the stylesheet keys on.
    $page->script("document.documentElement.classList.add('dark')");
    themeWaitUntil($page, 'document.documentElement.classList.contains("dark")');

    $darkColor = themeComputed($page, THEME_NOTE, 'color');
    $badgeColor = themeComputed($page, THEME_BADGE, 'color');
    $badgeBackground = themeComputed($page, THEME_BADGE, 'backgroundColor');

    expect($darkColor)->not->toBe('')->not->toBe($lightColor)
        ->and($badgeColor)->not->toBe('')->not->toBe($badgeBackground);
});
