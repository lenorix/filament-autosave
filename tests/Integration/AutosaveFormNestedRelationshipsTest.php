<?php

use Filament\Forms\Components\Repeater;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms\DeepRelationshipDraftForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms\DeepRelationshipRecordForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\PostItem;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\PostSubItem;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\PostSubSubItem;
use Livewire\Livewire;

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

function leafPath(array $data): string
{
    $itemKey = array_key_first($data['items']);
    $subKey = array_key_first($data['items'][$itemKey]['subitems']);
    $leafKey = array_key_first($data['items'][$itemKey]['subitems'][$subKey]['subsubitems']);

    return "items.{$itemKey}.subitems.{$subKey}.subsubitems.{$leafKey}";
}

function visibleLeafLabels(array $data): array
{
    return collect($data['items'])
        ->flatMap(fn ($i) => collect($i['subitems'])->flatMap(fn ($s) => collect($s['subsubitems'])->pluck('label')))
        ->all();
}

test('a generic record form undoes a nested edit three levels deep and shows the original value again', function () {
    [$post, , , $leaf] = seedDeepGraph();
    $page = Livewire::test(DeepRelationshipRecordForm::class, ['record' => $post]);
    $path = leafPath($page->get('data'));

    $page->set("data.{$path}.label", 'Option changed')->call('autosave')->assertSet('autosaveCanUndo', true);
    expect($leaf->fresh()->label)->toBe('Option changed');

    $page->call('undoAutosave')->assertDispatched('autosave-status', status: 'undone');

    expect($leaf->fresh()->label)->toBe('Option')
        ->and(visibleLeafLabels($page->get('data')))->toBe(['Option']);
});

test('a generic record form undoes a newly added nested row and it disappears from the form', function () {
    [$post, , $subitem] = seedDeepGraph();
    $page = Livewire::test(DeepRelationshipRecordForm::class, ['record' => $post]);
    $data = $page->get('data');
    $itemKey = array_key_first($data['items']);
    $subKey = array_key_first($data['items'][$itemKey]['subitems']);
    $leaves = $data['items'][$itemKey]['subitems'][$subKey]['subsubitems'];
    $leaves['new-leaf'] = ['label' => 'Added'];

    $page->set("data.items.{$itemKey}.subitems.{$subKey}.subsubitems", $leaves)
        ->call('autosave')->assertSet('autosaveCanUndo', true);
    expect(PostSubSubItem::query()->where('post_sub_item_id', $subitem->getKey())->count())->toBe(2);

    $page->call('undoAutosave')->assertDispatched('autosave-status', status: 'undone');

    expect(PostSubSubItem::query()->where('post_sub_item_id', $subitem->getKey())->pluck('label')->all())->toBe(['Option'])
        ->and(visibleLeafLabels($page->get('data')))->toBe(['Option']);
});
