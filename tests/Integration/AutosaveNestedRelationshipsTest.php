<?php

use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Lenorix\FilamentAutosave\HasAutosave;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Author;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\BuilderEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Category;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Comment;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\DeepRelationshipEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\MorphToEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\NestedRelationshipEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\PolymorphicEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\PostItem;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\PostSubItem;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\RelationshipEditPost;
use Livewire\Livewire;

test('autosave persists a changed belongsTo relation from a real select', function () {
    $category = Category::create(['name' => 'News']);
    $other = Category::create(['name' => 'Guides']);
    $post = Post::create(['title' => 'Post', 'category_id' => $category->getKey()]);

    Livewire::test(RelationshipEditPost::class, ['record' => $post->getKey()])
        ->set('data.category_id', $other->getKey())
        ->assertSet('data.category_id', $other->getKey())
        ->call('autosave');

    expect($post->fresh()->category_id)->toBe($other->getKey());
});

test('undo restores a changed belongsTo foreign key', function () {
    $category = Category::create(['name' => 'News']);
    $other = Category::create(['name' => 'Guides']);
    $post = Post::create(['title' => 'Post', 'category_id' => $category->getKey()]);

    Livewire::test(RelationshipEditPost::class, ['record' => $post->getKey()])
        ->set('data.category_id', $other->getKey())
        ->call('autosave')
        ->call('undoAutosave');

    expect($post->fresh()->category_id)->toBe($category->getKey());
});

test('autosave persists changed belongsToMany state from a real form', function () {
    $post = Post::create(['title' => 'Post']);
    $first = Author::create(['name' => 'First']);
    $second = Author::create(['name' => 'Second']);
    $post->authors()->attach($first);

    $page = Livewire::test(RelationshipEditPost::class, ['record' => $post->getKey()])
        ->set('data.authors', [$second->getKey()])
        ->call('autosave');

    expect($post->fresh()->authors->modelKeys())->toBe([$second->getKey()]);
});

test('autosave persists repeater relationship creates updates and deletes', function () {
    $post = Post::create(['title' => 'Post']);
    $old = PostItem::create(['post_id' => $post->getKey(), 'label' => 'Old', 'position' => 1]);

    $page = Livewire::test(RelationshipEditPost::class, ['record' => $post->getKey()]);
    $items = $page->get('data.items');
    $key = array_key_first($items);
    $page->set('data.items', [
        $key => ['label' => 'Updated', 'position' => 2],
        'new-row' => ['label' => 'New', 'position' => 1],
    ])->call('autosave');

    expect($post->fresh()->items()->orderBy('position')->pluck('label')->all())
        ->toBe(['New', 'Updated'])
        ->and(PostItem::query()->whereKey($old->getKey())->value('label'))->toBe('Updated');
});

test('undo restores belongsToMany pivots and repeater child records', function () {
    $post = Post::create(['title' => 'Post']);
    $first = Author::create(['name' => 'First']);
    $second = Author::create(['name' => 'Second']);
    $post->authors()->attach($first, ['role' => 'owner']);
    $item = PostItem::create(['post_id' => $post->getKey(), 'label' => 'Original', 'position' => 1]);

    $page = Livewire::test(RelationshipEditPost::class, ['record' => $post->getKey()]);
    $items = $page->get('data.items');
    $key = array_key_first($items);
    $page->set('data.authors', [$second->getKey()])
        ->set('data.items', [$key => ['label' => 'Changed', 'position' => 2]])
        ->call('autosave');

    expect($page->get('autosaveCanUndo'))->toBeTrue();

    $page->call('undoAutosave');

    expect($post->fresh()->authors->modelKeys())->toBe([$first->getKey()])
        ->and($post->fresh()->items()->value('label'))->toBe('Original')
        ->and(PostItem::query()->whereKey($item->getKey())->value('position'))->toBe(1);
});

test('a relation-only undo does not reuse a previous column snapshot', function () {
    $post = Post::create(['title' => 'Original']);
    $first = Author::create(['name' => 'First']);
    $second = Author::create(['name' => 'Second']);
    $post->authors()->attach($first);

    $page = Livewire::test(RelationshipEditPost::class, ['record' => $post->getKey()]);
    $page->set('data.title', 'Column save')->call('autosave');
    $page->set('data.authors', [$second->getKey()])->call('autosave')->call('undoAutosave');

    expect($post->fresh()->title)->toBe('Column save')
        ->and($post->fresh()->authors->modelKeys())->toBe([$first->getKey()]);
});

test('autosave persists a relationship repeater nested in a state path group', function () {
    $post = Post::create(['title' => 'Post']);
    PostItem::create(['post_id' => $post->getKey(), 'label' => 'Original', 'position' => 1]);

    $page = Livewire::test(NestedRelationshipEditPost::class, ['record' => $post->getKey()]);
    $items = $page->get('data.settings.items');
    $key = array_key_first($items);
    $page->set('data.settings.items', [$key => ['label' => 'Nested', 'position' => 1]])
        ->call('autosave');

    expect($post->fresh()->items()->value('label'))->toBe('Nested');
});

test('autosave and undo restore a deeply nested relationship graph', function () {
    $post = Post::create(['title' => 'Post']);
    $item = PostItem::create(['post_id' => $post->getKey(), 'label' => 'Item', 'position' => 1]);
    $subitem = PostSubItem::create(['post_item_id' => $item->getKey(), 'label' => 'Original']);

    $page = Livewire::test(DeepRelationshipEditPost::class, ['record' => $post->getKey()]);
    $items = $page->get('data.items');
    $itemKey = array_key_first($items);
    $subitemKey = array_key_first($items[$itemKey]['subitems']);

    $page->set("data.items.{$itemKey}.subitems.{$subitemKey}.label", 'Changed')
        ->call('autosave')
        ->call('undoAutosave');

    expect($subitem->fresh()->label)->toBe('Original');
});

test('autosave processes belongsTo fields in every repeated row', function () {
    $firstCategory = Category::create(['name' => 'First']);
    $secondCategory = Category::create(['name' => 'Second']);
    $post = Post::create(['title' => 'Post']);
    $first = PostItem::create([
        'post_id' => $post->getKey(),
        'category_id' => $firstCategory->getKey(),
        'label' => 'First item',
        'position' => 1,
    ]);
    $second = PostItem::create([
        'post_id' => $post->getKey(),
        'category_id' => $firstCategory->getKey(),
        'label' => 'Second item',
        'position' => 2,
    ]);

    $page = Livewire::test(DeepRelationshipEditPost::class, ['record' => $post->getKey()]);
    $items = $page->get('data.items');
    $firstKey = array_key_first($items);
    $secondKey = array_key_last($items);

    $page->set("data.items.{$firstKey}.category_id", $secondCategory->getKey())
        ->set("data.items.{$secondKey}.category_id", $secondCategory->getKey())
        ->call('autosave');

    expect($first->fresh()->category_id)->toBe($secondCategory->getKey())
        ->and($second->fresh()->category_id)->toBe($secondCategory->getKey());
});

test('autosave persists multiple Builder blocks in one request', function () {
    $post = Post::create(['title' => 'Post']);

    $page = Livewire::test(BuilderEditPost::class, ['record' => $post->getKey()]);
    $firstKey = 'first-block';

    $page->set('data.settings', [
        $firstKey => ['type' => 'text', 'data' => ['content' => 'First']],
        'second-block' => ['type' => 'text', 'data' => ['content' => 'Second']],
    ])->call('autosave');

    expect($post->fresh()->settings)->toMatchArray([
        0 => ['data' => ['content' => 'First']],
        1 => ['data' => ['content' => 'Second']],
    ]);
});

test('undo restores a HasManyThrough graph without nulling its intermediate key', function () {
    $post = Post::create(['title' => 'Post']);
    $item = PostItem::create(['post_id' => $post->getKey(), 'label' => 'Item', 'position' => 1]);
    $secondItem = PostItem::create(['post_id' => $post->getKey(), 'label' => 'Second item', 'position' => 2]);
    $subitem = PostSubItem::create(['post_item_id' => $item->getKey(), 'label' => 'Original']);
    $secondSubitem = PostSubItem::create(['post_item_id' => $secondItem->getKey(), 'label' => 'Second original']);

    $component = new class($post)
    {
        use HasAutosave;

        public function __construct(public Post $record) {}

        public function getRecord(): Post
        {
            return $this->record;
        }

        protected function getAutosaveStatePath(): string
        {
            return 'data';
        }

        protected function autosaveRelationshipFields(): array
        {
            return ['subitems' => [new class($this->record->subitems())
            {
                public function __construct(private HasManyThrough $relationship) {}

                public function getRelationship(): HasManyThrough
                {
                    return $this->relationship;
                }

                public function getStatePath(): string
                {
                    return 'data.subitems';
                }
            }]];
        }
    };

    $fields = (fn (): array => $this->autosaveRelationshipFields())->call($component);
    $snapshot = (fn (array $relationships): array => $this->captureAutosaveRelationshipUndo($relationships))
        ->call($component, $fields);
    $subitem->update(['label' => 'Changed']);
    $secondSubitem->update(['label' => 'Second changed']);
    PostSubItem::create(['post_item_id' => $item->getKey(), 'label' => 'Added']);

    (fn (array $snapshot) => $this->restoreAutosaveRelationshipUndo($snapshot))
        ->call($component, $snapshot);

    expect($post->subitems()->get())->toHaveCount(2)
        ->and($subitem->fresh()->label)->toBe('Original')
        ->and($subitem->fresh()->post_item_id)->toBe($item->getKey())
        ->and($secondSubitem->fresh()->label)->toBe('Second original')
        ->and($secondSubitem->fresh()->post_item_id)->toBe($secondItem->getKey());
});

test('autosave and undo restore multiple nested relationship branches', function () {
    $post = Post::create(['title' => 'Post']);
    $firstItem = PostItem::create(['post_id' => $post->getKey(), 'label' => 'First', 'position' => 1]);
    $secondItem = PostItem::create(['post_id' => $post->getKey(), 'label' => 'Second', 'position' => 2]);
    $firstSubitem = PostSubItem::create(['post_item_id' => $firstItem->getKey(), 'label' => 'First original']);
    $secondSubitem = PostSubItem::create(['post_item_id' => $secondItem->getKey(), 'label' => 'Second original']);

    $page = Livewire::test(DeepRelationshipEditPost::class, ['record' => $post->getKey()]);
    $items = $page->get('data.items');
    $firstKey = array_key_first($items);
    $secondKey = array_key_last($items);
    $firstChildKey = array_key_first($items[$firstKey]['subitems']);
    $secondChildKey = array_key_first($items[$secondKey]['subitems']);

    $page->set("data.items.{$firstKey}.subitems.{$firstChildKey}.label", 'First changed')
        ->set("data.items.{$secondKey}.subitems.{$secondChildKey}.label", 'Second changed')
        ->call('autosave')
        ->call('undoAutosave');

    expect($firstSubitem->fresh()->label)->toBe('First original')
        ->and($secondSubitem->fresh()->label)->toBe('Second original');
});

test('autosave and undo restore a MorphTo selection', function () {
    $category = Category::create(['name' => 'News']);
    $other = Category::create(['name' => 'Guides']);
    $post = Post::create([
        'title' => 'Post',
        'featured_type' => $category::class,
        'featured_id' => $category->getKey(),
    ]);

    Livewire::test(MorphToEditPost::class, ['record' => $post->getKey()])
        ->set('data.featured.featured_type', $other::class)
        ->set('data.featured.featured_id', $other->getKey())
        ->call('autosave')
        ->call('undoAutosave');

    expect($post->fresh()->featured_type)->toBe($category::class)
        ->and($post->fresh()->featured_id)->toBe($category->getKey());
});

test('autosave persists a morphMany repeater and restores it with undo', function () {
    $post = Post::create(['title' => 'Post']);
    $comment = Comment::create([
        'commentable_type' => $post::class,
        'commentable_id' => $post->getKey(),
        'body' => 'Original',
    ]);

    $page = Livewire::test(PolymorphicEditPost::class, ['record' => $post->getKey()]);
    $key = array_key_first($page->get('data.comments'));
    $page->set('data.comments', [$key => ['body' => 'Changed']])->call('autosave');

    expect($comment->fresh()->body)->toBe('Changed');

    $page->call('undoAutosave');

    expect($comment->fresh()->body)->toBe('Original');
});

class UnsavedAlertEditPost extends RelationshipEditPost
{
    protected function hasUnsavedDataChangesAlert(): bool
    {
        return true;
    }
}

test('a relationship-only autosave re-baselines the native unsaved-changes alert', function () {
    $post = Post::create(['title' => 'Post']);
    $first = Author::create(['name' => 'First']);
    $second = Author::create(['name' => 'Second']);
    $post->authors()->attach($first);

    $page = Livewire::test(UnsavedAlertEditPost::class, ['record' => $post->getKey()]);
    $before = $page->get('savedDataHash');

    $page->set('data.authors', [$second->getKey()])->call('autosave');

    $expected = md5((string) str(json_encode($page->get('data'), JSON_UNESCAPED_UNICODE))->replace('\\', ''));

    expect($page->get('savedDataHash'))->toBe($expected)->and($page->get('savedDataHash'))->not->toBe($before);
});
