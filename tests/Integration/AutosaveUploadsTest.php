<?php

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\PostItem;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\PostResource;
use Livewire\Livewire;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class UploadPost extends Post implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'posts';
}

class UploadPostResource extends PostResource
{
    protected static ?string $model = UploadPost::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            FileUpload::make('settings')->multiple()->reorderable()->disk('public')->maxSize(10),
            SpatieMediaLibraryFileUpload::make('gallery')->multiple()->reorderable()->disk('public')->maxSize(10),
        ]);
    }
}

class EditUploadPost extends EditPost
{
    protected static string $resource = UploadPostResource::class;
}

class FailingStoragePostResource extends UploadPostResource
{
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title'),
            FileUpload::make('settings')
                ->disk('public')
                ->saveUploadedFileUsing(fn (): never => throw new RuntimeException('storage unavailable')),
        ]);
    }
}

class EditFailingStoragePost extends EditUploadPost
{
    protected static string $resource = FailingStoragePostResource::class;
}

class EditFailingAfterValidateUploadPost extends EditUploadPost
{
    protected function afterValidate(): void
    {
        throw new RuntimeException('validation hook failed');
    }
}

beforeEach(function () {
    Storage::fake('public');
    $migration = require __DIR__.'/../../vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub';
    $migration->up();
});

test('a completed temporary upload is stored and its column saved without touching other columns', function () {
    $post = UploadPost::create(['title' => 'Original']);
    $page = Livewire::test(EditUploadPost::class, ['record' => $post->getKey()]);
    $post->update(['title' => 'Another editor']);
    $page->set('data.settings', [UploadedFile::fake()->create('document.txt', 1)])
        ->call('autosave')->assertDispatched('autosave-status', status: 'saved');
    $paths = $post->refresh()->settings;
    expect($paths)->toHaveCount(1)->and($post->title)->toBe('Another editor');
    Storage::disk('public')->assertExists($paths[0]);
    $page->call('autosave');
    expect(Storage::disk('public')->allFiles())->toHaveCount(1);
});

test('a storage failure reports an autosave error and leaves the record unchanged', function () {
    $post = UploadPost::create(['title' => 'Original']);

    Livewire::test(EditFailingStoragePost::class, ['record' => $post->getKey()])
        ->set('data.settings', [UploadedFile::fake()->create('document.txt', 1)])
        ->call('autosave')
        ->assertDispatched('autosave-status', status: 'error');

    expect($post->refresh()->settings)->toBeNull();
});

test('a later hook failure removes files stored during the autosave cycle', function () {
    $post = UploadPost::create(['title' => 'Original']);

    Livewire::test(EditFailingAfterValidateUploadPost::class, ['record' => $post->getKey()])
        ->set('data.settings', [UploadedFile::fake()->create('document.txt', 1)])
        ->call('autosave')
        ->assertDispatched('autosave-status', status: 'error');

    expect($post->refresh()->settings)->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('upload validation leaves existing files intact while another field saves', function () {
    Storage::disk('public')->put('existing.txt', 'existing');
    $post = UploadPost::create(['title' => 'Original', 'settings' => ['existing.txt']]);
    $page = Livewire::test(EditUploadPost::class, ['record' => $post->getKey()]);
    $page->set('data.settings', [UploadedFile::fake()->create('oversize.txt', 20)])
        ->set('data.title', 'Changed')->call('autosave');
    expect($post->refresh()->settings)->toBe(['existing.txt'])->and($post->title)->toBe('Changed');
    expect(Storage::disk('public')->allFiles())->toBe(['existing.txt']);
});

test('media uploads persist even when no record column changed and cannot offer column undo', function () {
    $post = UploadPost::create(['title' => 'Original']);
    $page = Livewire::test(EditUploadPost::class, ['record' => $post->getKey()]);
    $page->set('data.gallery', [UploadedFile::fake()->create('media.txt', 1)])
        ->call('autosave')->assertDispatched('autosave-status', status: 'saved')
        ->assertSet('autosaveCanUndo', false);
    expect($post->fresh()->getMedia())->toHaveCount(1);
    $page->call('autosave');
    expect($post->fresh()->getMedia())->toHaveCount(1);
});

test('media reorder and removal persist through their component callbacks', function () {
    $post = UploadPost::create(['title' => 'Original']);
    $first = $post->addMediaFromString('first')->usingFileName('first.txt')->toMediaCollection('default', 'public');
    $second = $post->addMediaFromString('second')->usingFileName('second.txt')->toMediaCollection('default', 'public');
    $page = Livewire::test(EditUploadPost::class, ['record' => $post->getKey()]);
    $page->set('data.gallery', [$second->uuid => $second->uuid, $first->uuid => $first->uuid])->call('autosave');
    expect($post->fresh()->getMedia()->pluck('uuid')->all())->toBe([$second->uuid, $first->uuid]);
    $page->set('data.gallery', [$second->uuid => $second->uuid])->call('autosave');
    expect($post->fresh()->getMedia()->pluck('uuid')->all())->toBe([$second->uuid]);
    $page->set('data.gallery', [])->call('autosave');
    expect($post->fresh()->getMedia())->toHaveCount(0);
});

test('unchanged media is not synchronized when another column changes', function () {
    $post = UploadPost::create(['title' => 'Original']);
    $page = Livewire::test(EditUploadPost::class, ['record' => $post->getKey()]);
    $post->addMediaFromString('external')->usingFileName('external.txt')->toMediaCollection('default', 'public');
    $page->set('data.title', 'Changed')->call('autosave');
    expect($post->fresh()->getMedia())->toHaveCount(1);
});

test('excluded uploads never reach permanent storage', function () {
    config(['filament-autosave.except' => ['settings', 'gallery']]);
    $post = UploadPost::create(['title' => 'Original']);
    Livewire::test(EditUploadPost::class, ['record' => $post->getKey()])
        ->set('data.settings', [UploadedFile::fake()->create('document.txt', 1)])
        ->set('data.gallery', [UploadedFile::fake()->create('media.txt', 1)])
        ->set('data.title', 'Changed')->call('autosave');
    expect($post->refresh()->settings)->toBeNull()->and($post->getMedia())->toHaveCount(0);
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

class HookedEditUploadPost extends EditUploadPost
{
    public int $beforeCalls = 0;

    protected function beforeAutosave(array $data): array
    {
        $this->beforeCalls++;
        unset($data['gallery']);

        return $data;
    }
}

class ValidatedEditUploadPost extends EditUploadPost
{
    protected function getAutosaveValidationRules(): array
    {
        return ['gallery' => ['array', 'max:0']];
    }
}

test('beforeAutosave runs once and can exclude a pending media operation', function () {
    $post = UploadPost::create(['title' => 'Original']);
    Livewire::test(HookedEditUploadPost::class, ['record' => $post->getKey()])
        ->set('data.settings', [UploadedFile::fake()->create('document.txt', 1)])
        ->set('data.gallery', [UploadedFile::fake()->create('media.txt', 1)])
        ->set('data.title', 'Changed')->call('autosave')->assertSet('beforeCalls', 1);
    expect($post->fresh()->getMedia())->toHaveCount(0);
    expect($post->refresh()->settings)->toHaveCount(1);
});

test('custom autosave rules can reject a pending media operation', function () {
    $post = UploadPost::create(['title' => 'Original']);
    Livewire::test(ValidatedEditUploadPost::class, ['record' => $post->getKey()])
        ->set('data.gallery', [UploadedFile::fake()->create('media.txt', 1)])
        ->set('data.title', 'Changed')->call('autosave');
    expect($post->fresh()->getMedia())->toHaveCount(0);
});

test('existing file ordering and clearing are saved without duplicating storage', function () {
    Storage::disk('public')->put('first.txt', 'first');
    Storage::disk('public')->put('second.txt', 'second');
    $post = UploadPost::create(['title' => 'Original', 'settings' => ['first.txt', 'second.txt']]);
    $page = Livewire::test(EditUploadPost::class, ['record' => $post->getKey()]);
    $page->set('data.settings', ['second' => 'second.txt', 'first' => 'first.txt'])->call('autosave');
    expect($post->refresh()->settings)->toBe(['second.txt', 'first.txt']);
    $page->set('data.settings', [])->call('autosave');
    expect($post->refresh()->settings)->toBe([]);
    expect(Storage::disk('public')->allFiles())->toHaveCount(2);
});

class NestedUploadPostResource extends UploadPostResource
{
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            Group::make([
                TextInput::make('caption'),
                FileUpload::make('files')->multiple()->disk('public')->maxSize(10),
            ])->statePath('settings'),
        ]);
    }
}

class EditNestedUploadPost extends EditUploadPost
{
    protected static string $resource = NestedUploadPostResource::class;
}

test('nested uploads preserve sibling values and store the whole JSON column', function () {
    $post = UploadPost::create(['title' => 'Original', 'settings' => ['caption' => 'Caption', 'files' => []]]);
    Livewire::test(EditNestedUploadPost::class, ['record' => $post->getKey()])
        ->set('data.settings.files', [UploadedFile::fake()->create('nested.txt', 1)])
        ->call('autosave')->assertDispatched('autosave-status', status: 'saved');
    expect($post->refresh()->settings['caption'])->toBe('Caption');
    expect($post->settings['files'])->toHaveCount(1);
    Storage::disk('public')->assertExists($post->settings['files'][0]);
});

test('a failed nested upload leaves its entire JSON column untouched', function () {
    $post = UploadPost::create(['title' => 'Original', 'settings' => ['caption' => 'Caption', 'files' => []]]);
    Livewire::test(EditNestedUploadPost::class, ['record' => $post->getKey()])
        ->set('data.settings.caption', 'New caption')
        ->set('data.settings.files', [UploadedFile::fake()->create('oversize.txt', 20)])
        ->set('data.title', 'Changed')->call('autosave');
    expect($post->refresh()->settings)->toBe(['caption' => 'Caption', 'files' => []]);
    expect($post->title)->toBe('Changed');
});

test('file name state is persisted alongside a new upload', function () {
    $post = UploadPost::create(['title' => 'Original']);
    $page = Livewire::test(EditNamedUploadPost::class, ['record' => $post->getKey()]);
    $page->set('data.slug', UploadedFile::fake()->create('original.txt', 1))->call('autosave');
    expect($post->refresh()->title)->toBe('original.txt');
    Storage::disk('public')->assertExists($post->slug);
});

class NamedUploadPostResource extends UploadPostResource
{
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title'),
            FileUpload::make('slug')->disk('public')->storeFileNamesIn('title'),
        ]);
    }
}

class EditNamedUploadPost extends EditUploadPost
{
    protected static string $resource = NamedUploadPostResource::class;
}

class SecretUploadPostResource extends UploadPostResource
{
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title'),
            Group::make([
                TextInput::make('secret')->password(),
                FileUpload::make('files')->multiple()->disk('public'),
            ])->statePath('settings'),
        ]);
    }
}

class EditSecretUploadPost extends EditUploadPost
{
    protected static string $resource = SecretUploadPostResource::class;
}

test('an upload in a skipped secret container is not stored as an orphan', function () {
    $post = UploadPost::create(['title' => 'Original', 'settings' => ['secret' => 'kept', 'files' => []]]);
    Livewire::test(EditSecretUploadPost::class, ['record' => $post->getKey()])
        ->set('data.settings.files', [UploadedFile::fake()->create('secret-container.txt', 1)])
        ->set('data.title', 'Changed')->call('autosave');
    expect($post->refresh()->settings)->toBe(['secret' => 'kept', 'files' => []]);
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

class DropUploadColumnEditPost extends EditUploadPost
{
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['settings']);

        return $data;
    }
}

test('a column dropped by the save mutation is not acknowledged as persisted', function () {
    $post = UploadPost::create(['title' => 'Original']);
    Livewire::test(DropUploadColumnEditPost::class, ['record' => $post->getKey()])
        ->set('data.settings', [UploadedFile::fake()->create('pending.txt', 1)])
        ->call('autosave')->assertDispatched('autosave-status', status: 'idle');
    expect($post->refresh()->settings)->toBeNull();
});

class ClearMediaInHookEditPost extends EditUploadPost
{
    protected function beforeAutosave(array $data): array
    {
        if (array_key_exists('gallery', $data)) {
            $data['gallery'] = [];
        }

        return $data;
    }
}

test('media callbacks receive the state accepted by the before hook', function () {
    $post = UploadPost::create(['title' => 'Original']);
    Livewire::test(ClearMediaInHookEditPost::class, ['record' => $post->getKey()])
        ->set('data.gallery', [UploadedFile::fake()->create('discarded.txt', 1)])
        ->call('autosave');
    expect($post->fresh()->getMedia())->toHaveCount(0);
});

class MediaPostItem extends PostItem implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'post_items';

    protected $fillable = ['post_id', 'label', 'position', 'attachment'];
}

class MediaItemsPost extends UploadPost
{
    public function items(): HasMany
    {
        return $this->hasMany(MediaPostItem::class, 'post_id');
    }
}

class MediaItemsPostResource extends UploadPostResource
{
    protected static ?string $model = MediaItemsPost::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            Repeater::make('items')
                ->relationship('items')
                ->schema([
                    TextInput::make('label')->required(),
                    FileUpload::make('attachment')->disk('public')->maxSize(10),
                    SpatieMediaLibraryFileUpload::make('images')->multiple()->reorderable()->disk('public')->maxSize(10),
                ]),
        ]);
    }
}

class EditMediaItemsPost extends EditUploadPost
{
    protected static string $resource = MediaItemsPostResource::class;
}

test('media nested in a relationship repeater row persists for an existing row', function () {
    $post = MediaItemsPost::create(['title' => 'Original']);
    $item = MediaPostItem::create(['post_id' => $post->getKey(), 'label' => 'Row', 'position' => 1]);
    $page = Livewire::test(EditMediaItemsPost::class, ['record' => $post->getKey()]);
    $key = array_key_first($page->get('data.items'));

    $page->set("data.items.{$key}.images", [UploadedFile::fake()->create('row.txt', 1)])
        ->call('autosave')->assertDispatched('autosave-status', status: 'saved')
        ->assertSet('autosaveCanUndo', false);

    expect($item->fresh()->getMedia())->toHaveCount(1)
        ->and($post->fresh()->getMedia())->toHaveCount(0);

    $page->call('autosave');
    expect($item->fresh()->getMedia())->toHaveCount(1);
});

test('media nested in a new relationship repeater row persists when the row is created', function () {
    $post = MediaItemsPost::create(['title' => 'Original']);
    $page = Livewire::test(EditMediaItemsPost::class, ['record' => $post->getKey()]);
    $page->set('data.items', ['new-row' => ['label' => 'New', 'images' => []]])
        ->set('data.items.new-row.images', [UploadedFile::fake()->create('new.txt', 1)])
        ->call('autosave')->assertDispatched('autosave-status', status: 'saved')
        ->assertSet('autosaveCanUndo', false);

    $item = $post->fresh()->items()->first();
    expect($item)->not->toBeNull()
        ->and($item->getMedia())->toHaveCount(1);

    $page->call('autosave');
    expect($item->fresh()->getMedia())->toHaveCount(1)
        ->and($post->fresh()->items()->count())->toBe(1);
});

test('a row failing validation keeps sibling rows and never stores its nested upload', function () {
    $post = MediaItemsPost::create(['title' => 'Original']);
    $item = MediaPostItem::create(['post_id' => $post->getKey(), 'label' => 'Row', 'position' => 1]);
    $other = MediaPostItem::create(['post_id' => $post->getKey(), 'label' => 'Other', 'position' => 2]);
    $page = Livewire::test(EditMediaItemsPost::class, ['record' => $post->getKey()]);
    $key = array_key_first($page->get('data.items'));

    $page->set("data.items.{$key}.label", '')
        ->set("data.items.{$key}.attachment", [UploadedFile::fake()->create('doc.txt', 1)])
        ->call('autosave')->assertDispatched('autosave-status', status: 'validation');

    expect($post->fresh()->items()->pluck('id')->all())->toBe([$item->getKey(), $other->getKey()])
        ->and($item->fresh()->attachment)->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('nested media reorder and removal persist for an existing row', function () {
    $post = MediaItemsPost::create(['title' => 'Original']);
    $item = MediaPostItem::create(['post_id' => $post->getKey(), 'label' => 'Row', 'position' => 1]);
    $first = $item->addMediaFromString('first')->usingFileName('first.txt')->toMediaCollection('default', 'public');
    $second = $item->addMediaFromString('second')->usingFileName('second.txt')->toMediaCollection('default', 'public');
    $page = Livewire::test(EditMediaItemsPost::class, ['record' => $post->getKey()]);
    $key = array_key_first($page->get('data.items'));

    $page->set("data.items.{$key}.images", [$second->uuid => $second->uuid, $first->uuid => $first->uuid])->call('autosave');
    expect($item->fresh()->getMedia()->pluck('uuid')->all())->toBe([$second->uuid, $first->uuid]);

    $page->set("data.items.{$key}.images", [$second->uuid => $second->uuid])->call('autosave');
    expect($item->fresh()->getMedia()->pluck('uuid')->all())->toBe([$second->uuid]);
});

test('an invalid nested media upload leaves the row media and other rows untouched', function () {
    $post = MediaItemsPost::create(['title' => 'Original']);
    $item = MediaPostItem::create(['post_id' => $post->getKey(), 'label' => 'Row', 'position' => 1]);
    $item->addMediaFromString('kept')->usingFileName('kept.txt')->toMediaCollection('default', 'public');
    $page = Livewire::test(EditMediaItemsPost::class, ['record' => $post->getKey()]);
    $key = array_key_first($page->get('data.items'));

    $page->set("data.items.{$key}.images", [UploadedFile::fake()->create('big.txt', 50)])
        ->call('autosave')->assertDispatched('autosave-status', status: 'validation');

    expect($item->fresh()->getMedia()->pluck('file_name')->all())->toBe(['kept.txt']);
});

test('a column upload nested in a relationship repeater row is stored', function () {
    $post = MediaItemsPost::create(['title' => 'Original']);
    $item = MediaPostItem::create(['post_id' => $post->getKey(), 'label' => 'Row', 'position' => 1]);
    $page = Livewire::test(EditMediaItemsPost::class, ['record' => $post->getKey()]);
    $key = array_key_first($page->get('data.items'));

    $page->set("data.items.{$key}.attachment", [UploadedFile::fake()->create('doc.txt', 1)])
        ->call('autosave')->assertDispatched('autosave-status', status: 'saved')
        ->assertSet('autosaveCanUndo', false);

    $stored = $item->fresh()->attachment;
    expect($stored)->toBeString()
        ->and(Storage::disk('public')->exists($stored))->toBeTrue();
});
