<?php

use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\DeepRelationshipEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\PostItem;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\PostSubItem;
use Livewire\Livewire;

class FailingAfterSaveEditPost extends EditPost
{
    protected function afterSave(): void
    {
        throw new RuntimeException('after save failed');
    }
}

class FailingAfterSaveDeepEditPost extends DeepRelationshipEditPost
{
    protected function afterSave(): void
    {
        throw new RuntimeException('after save failed');
    }
}

class TransactionLevelSpyEditPost extends EditPost
{
    public array $levels = [];

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $this->levels[] = DB::transactionLevel();

        return parent::handleRecordUpdate($record, $data);
    }
}

test('the test panel has database transactions disabled, as Filament does by default', function () {
    expect(Filament::getCurrentOrDefaultPanel()->hasDatabaseTransactions())->toBeFalse();
});

test('a hook failing after the column write rolls that write back even without panel transactions', function () {
    $post = Post::create(['title' => 'Original']);

    Livewire::test(FailingAfterSaveEditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Changed')
        ->call('autosave')
        ->assertDispatched('autosave-status', status: 'error');

    expect($post->fresh()->title)->toBe('Original');
});

test('a hook failing after nested relationship writes rolls back columns and rows together', function () {
    $post = Post::create(['title' => 'Original']);
    $item = PostItem::create(['post_id' => $post->getKey(), 'label' => 'Item', 'position' => 1]);
    $subitem = PostSubItem::create(['post_item_id' => $item->getKey(), 'label' => 'Original']);

    $page = Livewire::test(FailingAfterSaveDeepEditPost::class, ['record' => $post->getKey()]);
    $items = $page->get('data.items');
    $itemKey = array_key_first($items);
    $subitems = $items[$itemKey]['subitems'];
    $subKey = array_key_first($subitems);
    $subitems[$subKey]['label'] = 'Changed';
    $subitems['new-row'] = ['label' => 'Added', 'subsubitems' => []];

    $page->set('data.title', 'Changed')
        ->set("data.items.{$itemKey}.subitems", $subitems)
        ->call('autosave')
        ->assertDispatched('autosave-status', status: 'error');

    expect($post->fresh()->title)->toBe('Original')
        ->and($subitem->fresh()->label)->toBe('Original')
        ->and(PostSubItem::query()->where('post_item_id', $item->getKey())->count())->toBe(1);
});

test('the autosave write runs inside exactly one transaction level without panel transactions', function () {
    $post = Post::create(['title' => 'Original']);

    $page = Livewire::test(TransactionLevelSpyEditPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Changed')
        ->call('autosave');

    expect($page->get('levels'))->toBe([1])
        ->and($post->fresh()->title)->toBe('Changed');
});

test('the autosave write runs inside exactly one transaction level when the panel owns transactions', function () {
    Filament::getCurrentOrDefaultPanel()->databaseTransactions();
    $post = Post::create(['title' => 'Original']);

    try {
        $page = Livewire::test(TransactionLevelSpyEditPost::class, ['record' => $post->getKey()])
            ->set('data.title', 'Changed')
            ->call('autosave');
    } finally {
        Filament::getCurrentOrDefaultPanel()->databaseTransactions(false);
    }

    expect($page->get('levels'))->toBe([1])
        ->and($post->fresh()->title)->toBe('Changed');
});
