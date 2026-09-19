<?php

use Illuminate\Support\Facades\Storage;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;

/**
 * FilePond keeps a real `input[type=file]` in the DOM, so Playwright can hand
 * it a file; Livewire then POSTs it as multipart to `livewire/upload-file`.
 *
 * Gated behind PEST_BROWSER_UPLOADS=1 because pest-plugin-browser cannot yet
 * deliver a multipart upload to Laravel from its in-process server: 5.0.1
 * drops the body outright, and the unreleased 5.x branch keeps `files[]`
 * under a literal key so Livewire sees no file. The exact diagnosis and a
 * verified two-hunk patch are in tests/Browser/UPSTREAM_ISSUE.md; with that
 * patch applied this test is green end to end, so it is a real contract, not
 * a wish. Flip the env var on once the fix ships.
 */
test('uploading a file through FilePond autosaves the path, survives reload, and removing it clears the column', function () {
    Storage::fake('public');
    $post = Post::create(['title' => 'With upload']);

    $page = visit("/admin/upload-posts/{$post->getKey()}/edit");
    $this->waitUntil($page, 'document.querySelector(".filepond--root") !== null', 'FilePond to initialise');

    $page->attach('input[type=file]', __DIR__.'/../Fixtures/files/hello.txt');

    // The upload finishes, the controller autosaves, and a follow-up cycle can
    // report "unchanged" within the same second, so the "saved" badge is too
    // transient to poll for; the write landing is the durable fact.
    $this->waitForDatabase($page, fn (): bool => filled($post->fresh()->settings), 'the uploaded path to be saved', 15_000);

    $paths = $post->fresh()->settings;
    expect($paths)->toBeArray()->toHaveCount(1);
    Storage::disk('public')->assertExists($paths[0]);
    $this->waitUntil($page, 'document.querySelector(".filepond--item")?.getAttribute("data-filepond-item-state") === "idle"', 'FilePond to settle');

    $reloaded = visit("/admin/upload-posts/{$post->getKey()}/edit");
    $this->waitUntil($reloaded, 'document.querySelector(".filepond--file") !== null', 'the stored file to be listed');
    // Filament stores under a hashed name unless preserveFilenames() is set,
    // and lists that stored name, not the original one.
    $reloaded->assertSee(basename($paths[0]));

    $reloaded->click('.filepond--action-remove-item');
    $this->waitForDatabase($reloaded, fn (): bool => blank($post->fresh()->settings), 'the column to be cleared', 15_000);

    expect($post->fresh()->settings)->toBeEmpty();
    $this->assertNoBrowserErrors($reloaded);
})->skip(
    getenv('PEST_BROWSER_UPLOADS') !== '1',
    'pest-plugin-browser cannot deliver multipart uploads from its in-process server yet (see tests/Browser/UPSTREAM_ISSUE.md); set PEST_BROWSER_UPLOADS=1 to run against a patched plugin',
);
