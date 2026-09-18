<?php

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\HasAutosaveForForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\PostItem;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\PostSubItem;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\PostSubSubItem;
use Livewire\Component;
use Livewire\Livewire;

class DeepRelationshipRecordForm extends Component implements HasSchemas
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
                Repeater::make('items')->relationship('items')->schema([
                    TextInput::make('label'),
                    TextInput::make('position')->numeric(),
                    Repeater::make('subitems')->relationship('subitems')->schema([
                        TextInput::make('label'),
                        Repeater::make('subsubitems')->relationship('subsubitems')->schema([
                            TextInput::make('label'),
                        ]),
                    ]),
                ]),
            ])
            ->statePath('data');
    }

    public function getRecord(): Post
    {
        return $this->record;
    }

    protected function getAutosaveFormContext(): string
    {
        return 'deep:'.$this->record->getKey();
    }

    public function render(): string
    {
        return '<div></div>';
    }
}

function seedDeepGraph(): array
{
    $post = Post::create(['title' => 'Post']);
    $item = PostItem::create(['post_id' => $post->getKey(), 'label' => 'Service', 'position' => 1]);
    $subitem = PostSubItem::create(['post_item_id' => $item->getKey(), 'label' => 'Group']);
    $leaf = PostSubSubItem::create(['post_sub_item_id' => $subitem->getKey(), 'label' => 'Option']);

    return [$post, $item, $subitem, $leaf];
}

test('a generic record form persists a change three relationship levels deep on existing rows', function (bool $dirtyOnly) {
    config(['filament-autosave.dirty_only' => $dirtyOnly]);
    [$post, $item, $subitem, $leaf] = seedDeepGraph();

    $page = Livewire::test(DeepRelationshipRecordForm::class, ['record' => $post]);
    $items = $page->get('data.items');
    $itemKey = array_key_first($items);
    $subKey = array_key_first($items[$itemKey]['subitems']);
    $leafKey = array_key_first($items[$itemKey]['subitems'][$subKey]['subsubitems']);

    $page->set("data.items.{$itemKey}.subitems.{$subKey}.subsubitems.{$leafKey}.label", 'Option changed')
        ->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');

    expect($leaf->fresh()->label)->toBe('Option changed')
        ->and($subitem->fresh()->label)->toBe('Group')
        ->and($item->fresh()->label)->toBe('Service');
})->with(['dirty_only on' => true, 'dirty_only off' => false]);

test('a generic record form creates a new parent row with nested children once', function () {
    $post = Post::create(['title' => 'Post']);

    Livewire::test(DeepRelationshipRecordForm::class, ['record' => $post])
        ->set('data.items', [
            'new-item' => [
                'label' => 'New service',
                'position' => 1,
                'subitems' => [
                    'new-sub' => [
                        'label' => 'New group',
                        'subsubitems' => ['new-leaf' => ['label' => 'New option']],
                    ],
                ],
            ],
        ])
        ->call('autosave')->assertDispatched('autosave-status', status: 'saved');

    $item = PostItem::query()->where('post_id', $post->getKey())->get();
    expect($item)->toHaveCount(1);
    $sub = PostSubItem::query()->where('post_item_id', $item->first()->getKey())->get();
    expect($sub)->toHaveCount(1)
        ->and(PostSubSubItem::query()->where('post_sub_item_id', $sub->first()->getKey())->count())->toBe(1);
});

class DeepRelationshipDraftForm extends Component implements HasSchemas
{
    use HasAutosaveForForm;
    use InteractsWithSchemas;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
        $this->mountHasAutosaveForForm();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title'),
                Repeater::make('items')->schema([
                    TextInput::make('label'),
                    Repeater::make('subitems')->schema([
                        TextInput::make('label'),
                        Repeater::make('subsubitems')->schema([
                            TextInput::make('label'),
                        ]),
                    ]),
                ]),
            ])
            ->statePath('data');
    }

    protected function getAutosaveFormContext(): string
    {
        return 'deep-draft';
    }

    public function render(): string
    {
        return '<div></div>';
    }
}

test('a recordless draft keeps a three-level nested repeater state intact across store and restore', function () {
    $nested = [
        'row-1' => [
            'label' => 'Service',
            'subitems' => [
                'sub-1' => [
                    'label' => 'Group',
                    'subsubitems' => ['leaf-1' => ['label' => 'Option']],
                ],
            ],
        ],
    ];

    Livewire::test(DeepRelationshipDraftForm::class)
        ->set('data.items', $nested)
        ->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved');

    $restored = Livewire::test(DeepRelationshipDraftForm::class)
        ->assertSet('autosaveHasDraft', true)
        ->call('restoreDraft');

    // Filament re-keys repeater rows with fresh UUIDs on fill(), so walk the
    // values rather than the original keys.
    $items = array_values($restored->get('data')['items']);
    $subitems = array_values($items[0]['subitems']);
    $leaves = array_values($subitems[0]['subsubitems']);

    expect($items[0]['label'])->toBe('Service')
        ->and($subitems[0]['label'])->toBe('Group')
        ->and($leaves[0]['label'])->toBe('Option');
});
