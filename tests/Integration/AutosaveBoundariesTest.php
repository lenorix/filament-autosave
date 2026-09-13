<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\CreatePost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

class FailingAutosaveEditPost extends EditPost
{
    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        parent::handleRecordUpdate($record, $data);

        throw new RuntimeException('Failure after writing the record');
    }
}

test('a failed autosave undoes a genuine database update', function () {
    $post = Post::create(['title' => 'Original']);

    Livewire::test(FailingAutosaveEditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Changed')
        ->call('autosave')
        ->assertDispatched('autosave-status', status: 'error');

    expect($post->refresh()->title)->toBe('Original')
        ->and(DB::transactionLevel())->toBe(0);
});

test('a failed undo undoes a genuine database update', function () {
    $post = Post::create(['title' => 'Original']);
    $component = Livewire::test(FailingAutosaveEditPost::class, ['record' => $post->getKey()]);
    $page = $component->instance();
    (fn () => $this->storeUndoSnapshot(['title']))->call($page);
    $post->update(['title' => 'Saved']);

    $page->autosaveCanUndo = true;
    $page->undoAutosave();

    expect($post->refresh()->title)->toBe('Saved')
        ->and(DB::transactionLevel())->toBe(0)
        ->and($page->autosaveCanUndo)->toBeTrue();
});

test('Livewire refuses client writes to locked edit properties', function (string $property, mixed $value) {
    $post = Post::create(['title' => 'Original']);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set($property, $value);
})->with([
    ['autosaveEnabled', false],
    ['autosaveSnapshotHash', 'forged'],
    ['autosaveDebounceMs', 1],
    ['autosaveCanUndo', true],
    ['autosaveFieldHashes', []],
    ['autosaveUploadHashes', []],
    ['autosaveObservedHash', 'forged'],
    ['autosaveValidationErrors', []],
    ['autosaveValidationKeys', []],
])->throws(CannotUpdateLockedPropertyException::class);

test('Livewire refuses client writes to the draft availability flag', function () {
    Livewire::test(CreatePost::class)->set('autosaveHasDraft', true);
})->throws(CannotUpdateLockedPropertyException::class);
