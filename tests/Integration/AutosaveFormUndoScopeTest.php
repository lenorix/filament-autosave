<?php

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\Contracts\AutosaveExternalUndoAdapter;
use Lenorix\FilamentAutosave\HasAutosaveForForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Author;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\PostItem;
use Livewire\Component;
use Livewire\Livewire;

class TwoRelationshipsRecordForm extends Component implements HasSchemas
{
    use HasAutosaveForForm;
    use InteractsWithSchemas;

    public Post $record;

    public ?array $data = [];

    public function mount(Post $record): void
    {
        $this->record = $record;
        $this->form->fill($record->attributesToArray());
        $this->form->loadStateFromRelationships();
        $this->mountHasAutosaveForForm();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->model($this->record)
            ->components([
                TextInput::make('title'),
                CheckboxList::make('authors')->relationship('authors', 'name'),
                Repeater::make('items')->relationship('items')->schema([
                    TextInput::make('label'),
                ]),
            ])
            ->statePath('data');
    }

    protected function getFormModel(): Post
    {
        return $this->record;
    }

    public function getRecord(): Post
    {
        return $this->record;
    }

    public function render(): string
    {
        return '<div></div>';
    }
}

test('undoing a generic form autosave is not cancelled by a concurrent change to an untouched relationship', function () {
    $post = Post::create(['title' => 'Post']);
    $first = Author::create(['name' => 'First']);
    $second = Author::create(['name' => 'Second']);
    $post->authors()->attach($first);
    $item = PostItem::create(['post_id' => $post->getKey(), 'label' => 'Original', 'position' => 1]);

    $page = Livewire::test(TwoRelationshipsRecordForm::class, ['record' => $post]);

    // Only the authors relationship is touched by this autosave.
    $page->set('data.authors', [$second->getKey()])->call('autosave');
    expect($post->fresh()->authors->modelKeys())->toBe([$second->getKey()]);

    // A second editor changes the untouched `items` relationship in between.
    $item->update(['label' => 'Changed elsewhere']);

    $page->call('undoAutosave');

    expect($post->fresh()->authors->modelKeys())->toBe([$first->getKey()])
        ->and(PostItem::query()->whereKey($item->getKey())->value('label'))->toBe('Changed elsewhere');
});

test('undo is still cancelled when the touched relationship changes concurrently', function () {
    $post = Post::create(['title' => 'Post']);
    $first = Author::create(['name' => 'First']);
    $second = Author::create(['name' => 'Second']);
    $third = Author::create(['name' => 'Third']);
    $post->authors()->attach($first);

    $page = Livewire::test(TwoRelationshipsRecordForm::class, ['record' => $post]);
    $page->set('data.authors', [$second->getKey()])->call('autosave');

    // A second editor changes the SAME relationship this autosave touched.
    $post->authors()->sync([$third->getKey()]);

    $page->call('undoAutosave');

    expect($post->fresh()->authors->modelKeys())->toBe([$third->getKey()]);
});

class AlwaysMismatchingItemsAdapter implements AutosaveExternalUndoAdapter
{
    public function supports(object $field): bool
    {
        return method_exists($field, 'getRelationship') && $field->getRelationship() !== null;
    }

    public function snapshot(object $field): array
    {
        return [];
    }

    public function matches(object $field, array $snapshot): bool
    {
        return method_exists($field, 'getStatePath') && $field->getStatePath() !== 'data.items';
    }

    public function restore(object $field, array $snapshot): void {}
}

test('undoing a generic form autosave is not blocked by an external adapter for an untouched relationship', function () {
    config(['filament-autosave.external_undo_adapters' => [new AlwaysMismatchingItemsAdapter]]);

    $post = Post::create(['title' => 'Post']);
    $first = Author::create(['name' => 'First']);
    $second = Author::create(['name' => 'Second']);
    $post->authors()->attach($first);
    PostItem::create(['post_id' => $post->getKey(), 'label' => 'Original', 'position' => 1]);

    $page = Livewire::test(TwoRelationshipsRecordForm::class, ['record' => $post]);
    $page->set('data.authors', [$second->getKey()])->call('autosave');

    $page->call('undoAutosave');

    expect($post->fresh()->authors->modelKeys())->toBe([$first->getKey()]);
});

class AlwaysMismatchingAuthorsAdapter implements AutosaveExternalUndoAdapter
{
    public function supports(object $field): bool
    {
        return method_exists($field, 'getRelationship') && $field->getRelationship() !== null;
    }

    public function snapshot(object $field): array
    {
        return [];
    }

    public function matches(object $field, array $snapshot): bool
    {
        return method_exists($field, 'getStatePath') && $field->getStatePath() !== 'data.authors';
    }

    public function restore(object $field, array $snapshot): void {}
}

test('undo is still blocked by an external adapter mismatch on the touched relationship', function () {
    config(['filament-autosave.external_undo_adapters' => [new AlwaysMismatchingAuthorsAdapter]]);

    $post = Post::create(['title' => 'Post']);
    $first = Author::create(['name' => 'First']);
    $second = Author::create(['name' => 'Second']);
    $post->authors()->attach($first);

    $page = Livewire::test(TwoRelationshipsRecordForm::class, ['record' => $post]);
    $page->set('data.authors', [$second->getKey()])->call('autosave');

    $page->call('undoAutosave');

    expect($post->fresh()->authors->modelKeys())->toBe([$second->getKey()]);
});
