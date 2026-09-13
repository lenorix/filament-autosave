<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Author;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\AutosaveActionForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\AutosaveCommentForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\AutosaveCommentsRelationManager;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\AutosavePostForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\AutosaveTableForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\AutosaveUploadForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\AutosaveUploadRecordForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Comment;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;
use Livewire\Livewire;

class AutosaveDropUploadRecordForm extends AutosaveUploadRecordForm
{
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['settings']);

        return $data;
    }
}

test('a relation manager can opt into an isolated autosave draft', function () {
    $post = Post::create(['title' => 'Post']);

    Livewire::test(AutosaveCommentsRelationManager::class, [
        'ownerRecord' => $post,
        'pageClass' => 'Lenorix\\FilamentAutosave\\Tests\\Fixtures\\Integration\\RelationshipEditPost',
    ])
        ->set('data.body', 'Draft comment')
        ->call('autosave')
        ->assertSet('autosaveHasDraft', true)
        ->call('discardDraft')
        ->assertSet('autosaveHasDraft', false);
});

test('a relation manager action persists and undoes its related record', function () {
    $post = Post::create(['title' => 'Post']);
    $comment = Comment::create([
        'body' => 'Original comment',
        'commentable_type' => $post->getMorphClass(),
        'commentable_id' => $post->getKey(),
    ]);

    $page = Livewire::test(AutosaveCommentsRelationManager::class, [
        'ownerRecord' => $post,
        'pageClass' => 'Lenorix\\FilamentAutosave\\Tests\\Fixtures\\Integration\\RelationshipEditPost',
    ]);
    $instance = $page->instance();
    $instance->mountTableAction('edit', (string) $comment->getKey());
    $instance->fillMountedEdit(['body' => 'Updated comment']);
    $instance->autosave();
    expect($instance->autosaveCanUndo)->toBeTrue();
    $instance->undoAutosave();

    expect($comment->refresh()->body)->toBe('Original comment');
});

test('a model-backed generic form persists and undoes changes', function () {
    $post = Post::create(['title' => 'Post']);
    $comment = Comment::create([
        'body' => 'Original comment',
        'commentable_type' => $post->getMorphClass(),
        'commentable_id' => $post->getKey(),
    ]);

    Livewire::test(AutosaveCommentForm::class, ['record' => $comment])
        ->set('data.body', 'Updated comment')
        ->call('autosave')
        ->assertSet('autosaveCanUndo', true)
        ->assertDispatched('autosave-status', status: 'saved')
        ->call('undoAutosave')
        ->assertSet('autosaveCanUndo', false)
        ->assertDispatched('autosave-status', status: 'undone');

    expect($comment->refresh()->body)->toBe('Original comment');
});

test('a mounted action form persists and undoes its record', function () {
    $post = Post::create(['title' => 'Post']);

    Livewire::test(AutosaveActionForm::class, ['record' => $post])
        ->set('mountedActions.0.data.title', 'Action title')
        ->call('autosave')
        ->assertSet('autosaveCanUndo', true)
        ->call('undoAutosave');

    expect($post->fresh()->title)->toBe('Post');
});

test('a table action form persists and undoes its row', function () {
    $post = Post::create(['title' => 'Post']);

    Livewire::test(AutosaveTableForm::class)
        ->call('mountTableAction', 'edit', (string) $post->getKey())
        ->set('mountedActions.0.data.title', 'Table title')
        ->call('autosave')
        ->assertSet('autosaveCanUndo', true)
        ->call('undoAutosave');

    expect($post->fresh()->title)->toBe('Post');
});

test('generic drafts strip uploads while retaining ordinary fields', function () {
    Storage::fake('public');

    Livewire::test(AutosaveUploadForm::class)
        ->set('data.title', 'Draft title')
        ->set('data.attachment', UploadedFile::fake()->create('document.txt', 1))
        ->call('autosave')
        ->assertSet('autosaveHasDraft', true);

    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('a record-backed generic form stores a validated upload', function () {
    Storage::fake('public');
    $post = Post::create(['title' => 'Post']);

    $page = Livewire::test(AutosaveUploadRecordForm::class, ['record' => $post])
        ->set('data.settings', [UploadedFile::fake()->create('document.txt', 1)]);
    $page->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved')
        ->assertSet('autosaveCanUndo', false);

    $path = $post->fresh()->settings[0];
    Storage::disk('public')->assertExists($path);
});

test('generic upload saves cannot be undone as a column-only operation', function () {
    Storage::fake('public');
    Storage::disk('public')->put('existing.txt', 'existing');
    $post = Post::create(['title' => 'Post', 'settings' => ['existing.txt']]);

    Livewire::test(AutosaveUploadRecordForm::class, ['record' => $post])
        ->set('data.settings', [UploadedFile::fake()->create('document.txt', 1)])
        ->call('autosave')
        ->assertSet('autosaveCanUndo', false)
        ->call('undoAutosave')
        ->assertDispatched('autosave-status', status: 'idle');

    expect($post->fresh()->settings)->toContain('existing.txt')->toHaveCount(2)
        ->and(Storage::disk('public')->allFiles())->toHaveCount(2);
});

test('a generic mutator that drops an upload leaves no orphaned file', function () {
    Storage::fake('public');
    $post = Post::create(['title' => 'Post']);

    Livewire::test(AutosaveDropUploadRecordForm::class, ['record' => $post])
        ->set('data.title', 'Changed')
        ->set('data.settings', [UploadedFile::fake()->create('document.txt', 1)])
        ->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');

    expect($post->fresh()->title)->toBe('Changed')
        ->and($post->fresh()->settings)->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('a generic form persists and undoes a relationship callback', function () {
    $post = Post::create(['title' => 'Post']);
    $first = Author::create(['name' => 'First']);
    $second = Author::create(['name' => 'Second']);
    $post->authors()->attach($first);

    Livewire::test(AutosavePostForm::class, ['record' => $post])
        ->set('data.authors', [$second->getKey()])
        ->call('autosave')
        ->assertSet('autosaveCanUndo', true)
        ->call('undoAutosave');

    expect($post->fresh()->authors->modelKeys())->toBe([$first->getKey()]);
});
