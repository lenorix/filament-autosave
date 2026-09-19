<?php

use Illuminate\Support\Facades\Storage;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;

/**
 * FilePond keeps a real `input[type=file]` in the DOM, so Playwright can hand
 * it a file; Livewire then POSTs it as multipart to `livewire/upload-file`.
 *
 * Blocked by the browser plugin, not by the package: pest-plugin-browser
 * 5.0.1 serves the app in-process through `LaravelHttpServer::handleRequest()`,
 * which builds the Symfony request with an empty files array
 * (`[], // @TODO files...`) and only parses `application/x-www-form-urlencoded`
 * bodies. A multipart upload therefore reaches Livewire with no file, the
 * temporary-upload endpoint never completes, and FilePond stays in
 * `processing` indefinitely (verified: item state `processing` for 8 s, no
 * XHR error, column untouched). `attach()`/`setInputFiles()` themselves work.
 * Re-enable when the plugin forwards multipart bodies; the assertions below
 * are the intended contract.
 */
test('uploading a file through FilePond autosaves the path, survives reload, and removing it clears the column', function () {
    Storage::fake('public');
    $post = Post::create(['title' => 'With upload']);

    $page = visit("/admin/upload-posts/{$post->getKey()}/edit");
    $this->waitUntil($page, 'document.querySelector(".filepond--root") !== null', 'FilePond to initialise');

    $page->attach('input[type=file]', __DIR__.'/../Fixtures/files/hello.txt');
    $this->waitForStatus($page, 'saved');

    $paths = $post->fresh()->settings;
    expect($paths)->toBeArray()->toHaveCount(1);
    Storage::disk('public')->assertExists($paths[0]);

    $reloaded = visit("/admin/upload-posts/{$post->getKey()}/edit");
    $this->waitUntil($reloaded, 'document.querySelector(".filepond--file") !== null', 'the stored file to be listed');
    $reloaded->assertSee('hello.txt');

    $reloaded->click('.filepond--action-remove-item');
    $this->waitForStatus($reloaded, 'saved');

    expect($post->fresh()->settings)->toBeEmpty();
})->todo('pest-plugin-browser 5.0.1 drops multipart bodies (LaravelHttpServer: "@TODO files"), so Livewire temporary uploads never complete in-process');
