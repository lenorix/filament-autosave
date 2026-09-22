<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Lenorix\FilamentAutosave\ExternalUndoAdapters\FilesystemUndoAdapter;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms\AutosaveSingleUploadRecordForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms\AutosaveUploadRecordForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;
use Livewire\Livewire;

/*
 * The shipped adapter for plain FileUpload Undo. `settings` on Post is a
 * multiple() FileUpload on the "public" disk (see AutosaveUploadRecordForm).
 */

function withPublicAdapter(): void
{
    config(['filament-autosave.external_undo_adapters' => [
        new FilesystemUndoAdapter('public'),
    ]]);
}

test('a newly stored file is deleted and the column cleared when undone', function () {
    Storage::fake('public');
    withPublicAdapter();
    $post = Post::create(['title' => 'Post']);

    $page = Livewire::test(AutosaveUploadRecordForm::class, ['record' => $post]);
    $page->set('data.settings', [UploadedFile::fake()->create('one.txt', 10)])
        ->call('autosave')
        ->assertSet('autosaveCanUndo', true);

    $stored = $post->fresh()->settings;
    expect($stored)->toHaveCount(1);
    Storage::disk('public')->assertExists($stored[0]);

    $page->call('undoAutosave')
        ->assertDispatched('autosave-status', status: 'undone');

    Storage::disk('public')->assertMissing($stored[0]);
    expect($post->fresh()->settings)->toBeEmpty();
});

test('a file replaced by another is restored and the new file is deleted when undone', function () {
    Storage::fake('public');
    withPublicAdapter();

    // A single (non-multiple) FileUpload on a plain string column: setting a
    // new file genuinely replaces the old value, rather than the multiple()
    // case where the package deliberately keeps files still on disk that the
    // current session's state simply doesn't mention.
    $firstPath = 'body/pre-existing.txt';
    Storage::disk('public')->put($firstPath, 'first content');
    $post = Post::create(['title' => 'Post', 'body' => $firstPath]);

    $page = Livewire::test(AutosaveSingleUploadRecordForm::class, ['record' => $post]);

    // Filament tracks a FileUpload's state as a keyed array even when the
    // field is not multiple(); setting the state path directly would append
    // rather than replace, so drop the old entry's key before adding the new
    // upload under its own key, matching what removing then attaching a file
    // in the widget produces.
    $oldKey = array_key_first($page->get('data.body'));
    $page->set("data.body.{$oldKey}", null);
    $page->set('data.body', array_filter($page->get('data.body'), fn ($v) => $v !== null));
    $page->set('data.body.new', UploadedFile::fake()->create('second.txt', 20));

    $page->call('autosave')
        ->assertSet('autosaveCanUndo', true);

    $secondPath = $post->fresh()->body;
    expect($secondPath)->not->toBe($firstPath);
    Storage::disk('public')->assertExists($secondPath);

    $page->call('undoAutosave');

    Storage::disk('public')->assertMissing($secondPath);
    Storage::disk('public')->assertExists($firstPath);
    expect(Storage::disk('public')->get($firstPath))->toBe('first content')
        ->and($post->fresh()->body)->toBe($firstPath);
});

test('undo is available when nothing external changed since the write', function () {
    Storage::fake('public');
    withPublicAdapter();
    $post = Post::create(['title' => 'Post']);

    $page = Livewire::test(AutosaveUploadRecordForm::class, ['record' => $post]);
    $page->set('data.title', 'Column only')->call('autosave');

    expect($page->get('autosaveCanUndo'))->toBeTrue();
    $page->call('undoAutosave')->assertDispatched('autosave-status', status: 'undone');
    expect($post->fresh()->title)->toBe('Post');
});

test('undo is refused as a conflict when the stored file was changed externally after the save', function () {
    Storage::fake('public');
    withPublicAdapter();
    $post = Post::create(['title' => 'Post']);

    $page = Livewire::test(AutosaveUploadRecordForm::class, ['record' => $post]);
    $page->set('data.settings', [UploadedFile::fake()->create('one.txt', 10)])
        ->call('autosave')
        ->assertSet('autosaveCanUndo', true);

    $stored = $post->fresh()->settings[0];
    Storage::disk('public')->delete($stored);

    $page->call('undoAutosave')->assertDispatched('autosave-status', status: 'conflict');

    expect($post->fresh()->settings)->toBe([$stored]);
});
