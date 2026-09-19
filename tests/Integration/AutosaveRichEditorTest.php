<?php

use Filament\Forms\Components\RichEditor;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms\PlainRichEditorRecordForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\PlainRichPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\RichUploadPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\RichUpload\PlainRichEditorEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\RichUpload\RichUploadEditPost;
use Livewire\Livewire;

beforeEach(function () {
    $migration = require __DIR__.'/../../vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub';
    $migration->up();
});

/** Filament 4.0.x typed RichEditor's dehydration hook as `?array`, rejecting HTML string state. */
function filamentRichEditorRejectsHtmlState(): bool
{
    // Filament 4.0.x typed the dehydration hook's state as `?array`, so an
    // HTML-string editor crashes inside Filament itself. Read the signature
    // rather than a version number.
    $method = new ReflectionMethod(RichEditor::class, 'setUp');
    $lines = array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);

    return str_contains(implode('', $lines), 'beforeStateDehydrated(function (RichEditor $component, ?array $rawState');
}

test('rich editor attachment cleanup runs during autosave and disables undo', function () {
    $post = RichUploadPost::create([
        'title' => 'Post',
        'body' => ['type' => 'doc', 'content' => []],
    ]);
    $media = $post->addMediaFromString('image')->usingFileName('image.png')->toMediaCollection('content', 'public');
    $post->update(['body' => [
        'type' => 'doc',
        'content' => [['type' => 'image', 'attrs' => ['id' => $media->uuid, 'src' => $media->getUrl(), 'alt' => null]]],
    ]]);

    $page = Livewire::test(RichUploadEditPost::class, ['record' => $post->getKey()]);
    $page->set('data.body', ['type' => 'doc', 'content' => []])->call('autosave');

    expect($post->fresh()->getMedia('content'))->toHaveCount(0)
        ->and($page->get('autosaveCanUndo'))->toBeFalse();
});

test('a plain rich editor without attachments autosaves its content as a column', function () {
    $post = PlainRichPost::create(['title' => 'Post', 'body' => '<p>old</p>']);

    $page = Livewire::test(PlainRichEditorEditPost::class, ['record' => $post->getKey()])
        ->set('data.body', '<p>new text</p>')
        ->call('autosave')->assertDispatched('autosave-status', status: 'saved');

    expect($post->fresh()->body)->toContain('new text');

    $page->call('undoAutosave');
    expect($post->fresh()->body)->toContain('old');
})->skip(fn (): bool => filamentRichEditorRejectsHtmlState(), 'Filament\'s RichEditor rejects HTML string state on this version (4.0.x)');

test('a rich editor with an attachment provider keeps its content while cleaning attachments', function () {
    $post = RichUploadPost::create(['title' => 'Post', 'body' => ['type' => 'doc', 'content' => []]]);
    $media = $post->addMediaFromString('image')->usingFileName('image.png')->toMediaCollection('content', 'public');
    $post->update(['body' => [
        'type' => 'doc',
        'content' => [['type' => 'image', 'attrs' => ['id' => $media->uuid, 'src' => $media->getUrl(), 'alt' => null]]],
    ]]);
    $body = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'kept']]]]];

    Livewire::test(RichUploadEditPost::class, ['record' => $post->getKey()])
        ->set('data.body', $body)->call('autosave')->assertDispatched('autosave-status', status: 'saved');

    expect($post->fresh()->body)->toBe($body)
        ->and($post->fresh()->getMedia('content'))->toHaveCount(0);
});

test('a generic record form autosaves plain rich editor content', function () {
    $post = PlainRichPost::create(['title' => 'Post', 'body' => '<p>old</p>']);

    Livewire::test(PlainRichEditorRecordForm::class, ['record' => $post])
        ->set('data.body', '<p>new text</p>')
        ->call('autosave')->assertDispatched('autosave-status', status: 'saved');

    expect($post->fresh()->body)->toContain('new text');
});
