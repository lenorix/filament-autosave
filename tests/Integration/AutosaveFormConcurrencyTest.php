<?php

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\HasAutosaveForForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;
use Livewire\Component;
use Livewire\Livewire;

class TwoColumnRecordForm extends Component implements HasSchemas
{
    use HasAutosaveForForm;
    use InteractsWithSchemas;

    public Post $record;

    public ?array $data = [];

    public function mount(Post $record): void
    {
        $this->record = $record;
        $this->form->fill($record->attributesToArray());
        $this->mountHasAutosaveForForm();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->model($this->record)
            ->components([
                TextInput::make('title'),
                TextInput::make('slug'),
            ])
            ->statePath('data');
    }

    public function getRecord(): Post
    {
        return $this->record;
    }

    protected function getAutosaveFormContext(): string
    {
        return 'two-columns:'.$this->record->getKey();
    }

    public function render(): string
    {
        return '<div></div>';
    }
}

// Mirror of "panel undo preserves a newer concurrent update" for generic
// forms: two live instances of the same component editing the same record.
test('two generic form instances editing different columns with dirty_only both persist without clobbering', function () {
    config(['filament-autosave.dirty_only' => true]);
    $post = Post::create(['title' => 'Original title', 'slug' => 'original-slug']);

    $first = Livewire::test(TwoColumnRecordForm::class, ['record' => $post]);
    $second = Livewire::test(TwoColumnRecordForm::class, ['record' => $post]);

    $first->set('data.title', 'Title from A')->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');
    expect($post->fresh()->only(['title', 'slug']))->toBe(['title' => 'Title from A', 'slug' => 'original-slug']);

    // B still holds the stale "Original title" locally; its write must only
    // carry the column it changed.
    $second->set('data.slug', 'slug-from-b')->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');

    expect($post->fresh()->only(['title', 'slug']))->toBe(['title' => 'Title from A', 'slug' => 'slug-from-b']);

    // And A, still holding the stale slug, must not push it back either.
    $first->set('data.title', 'Title from A again')->call('autosave');

    expect($post->fresh()->only(['title', 'slug']))->toBe(['title' => 'Title from A again', 'slug' => 'slug-from-b']);
});

// Red on purpose: generic-form Undo snapshots are keyed by scope + class +
// context + record (HasAutosaveForForm::getAutosaveFormUndoKey()), not by
// Livewire instance. Two tabs of the same user on the same record share one
// slot, so B's autosave overwrites A's snapshot and A's undo then restores
// B's column (slug) instead of its own (title). Fixing it needs a per-instance
// component of the key, which lives in src/ and is owned by another stream.
test('a generic form undo only restores the column it wrote and keeps the other instance\'s column', function () {
    config(['filament-autosave.dirty_only' => true]);
    $post = Post::create(['title' => 'Original title', 'slug' => 'original-slug']);

    $first = Livewire::test(TwoColumnRecordForm::class, ['record' => $post]);
    $second = Livewire::test(TwoColumnRecordForm::class, ['record' => $post]);

    $first->set('data.title', 'Title from A')->call('autosave')->assertSet('autosaveCanUndo', true);
    $second->set('data.slug', 'slug-from-b')->call('autosave');

    $first->call('undoAutosave')->assertDispatched('autosave-status', status: 'undone');

    expect($post->fresh()->only(['title', 'slug']))->toBe(['title' => 'Original title', 'slug' => 'slug-from-b']);
})->todo('generic-form Undo slot is shared between instances of the same user on the same record');
