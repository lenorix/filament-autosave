<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages\ClearMediaInHookEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages\DropUploadColumnEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages\EditFailingAfterValidateUploadPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages\FailingAfterSaveRowMediaPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages\FailingAfterSaveUploadPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages\HookedEditUploadPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages\LedgerSpyEditUploadPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages\ValidatedEditUploadPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms\MediaItemsRecordForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\MediaItemsPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\MediaPostItem;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\UploadPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload\EditFailingStoragePost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload\EditMediaItemsPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload\EditNamedUploadPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload\EditNestedUploadPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload\EditRowMediaPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload\EditSecretUploadPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload\EditSharedRowMediaPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload\EditUploadPost;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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

test('an upload in a skipped secret container is not stored as an orphan', function () {
    $post = UploadPost::create(['title' => 'Original', 'settings' => ['secret' => 'kept', 'files' => []]]);
    Livewire::test(EditSecretUploadPost::class, ['record' => $post->getKey()])
        ->set('data.settings.files', [UploadedFile::fake()->create('secret-container.txt', 1)])
        ->set('data.title', 'Changed')->call('autosave');
    expect($post->refresh()->settings)->toBe(['secret' => 'kept', 'files' => []]);
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('a column dropped by the save mutation is not acknowledged as persisted', function () {
    $post = UploadPost::create(['title' => 'Original']);
    Livewire::test(DropUploadColumnEditPost::class, ['record' => $post->getKey()])
        ->set('data.settings', [UploadedFile::fake()->create('pending.txt', 1)])
        ->call('autosave')->assertDispatched('autosave-status', status: 'idle');
    expect($post->refresh()->settings)->toBeNull();
});

test('media callbacks receive the state accepted by the before hook', function () {
    $post = UploadPost::create(['title' => 'Original']);
    Livewire::test(ClearMediaInHookEditPost::class, ['record' => $post->getKey()])
        ->set('data.gallery', [UploadedFile::fake()->create('discarded.txt', 1)])
        ->call('autosave');
    expect($post->fresh()->getMedia())->toHaveCount(0);
});

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

test('a generic record form persists media nested in an existing relationship row', function () {
    $post = MediaItemsPost::create(['title' => 'Original']);
    $item = MediaPostItem::create(['post_id' => $post->getKey(), 'label' => 'Row', 'position' => 1]);
    $page = Livewire::test(MediaItemsRecordForm::class, ['record' => $post]);
    $key = array_key_first($page->get('data.items'));

    $page->set("data.items.{$key}.images", [UploadedFile::fake()->create('row.txt', 1)])
        ->set("data.items.{$key}.attachment", [UploadedFile::fake()->create('doc.txt', 1)])
        ->call('autosave')->assertDispatched('autosave-status', status: 'saved')
        ->assertSet('autosaveCanUndo', false);

    expect($item->fresh()->getMedia())->toHaveCount(1)
        ->and(Storage::disk('public')->exists($item->fresh()->attachment))->toBeTrue();
});

test('a generic record form attaches media to a new relationship row without offering undo', function () {
    $post = MediaItemsPost::create(['title' => 'Original']);
    Livewire::test(MediaItemsRecordForm::class, ['record' => $post])
        ->set('data.items', ['new-row' => ['label' => 'New', 'images' => []]])
        ->set('data.items.new-row.images', [UploadedFile::fake()->create('new.txt', 1)])
        ->call('autosave')->assertDispatched('autosave-status', status: 'saved')
        ->assertSet('autosaveCanUndo', false);

    expect($post->fresh()->items()->first()?->getMedia())->toHaveCount(1);
});

test('a generic record form row failing validation stores no nested upload', function () {
    $post = MediaItemsPost::create(['title' => 'Original']);
    $item = MediaPostItem::create(['post_id' => $post->getKey(), 'label' => 'Row', 'position' => 1]);
    $page = Livewire::test(MediaItemsRecordForm::class, ['record' => $post]);
    $key = array_key_first($page->get('data.items'));

    $page->set("data.items.{$key}.label", '')
        ->set("data.items.{$key}.attachment", [UploadedFile::fake()->create('doc.txt', 1)])
        ->call('autosave');

    expect($post->fresh()->items()->count())->toBe(1)
        ->and($item->fresh()->attachment)->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('a generic record form persists nested row media with dirty_only enabled', function () {
    config(['filament-autosave.dirty_only' => true]);
    $post = MediaItemsPost::create(['title' => 'Original']);
    $item = MediaPostItem::create(['post_id' => $post->getKey(), 'label' => 'Row', 'position' => 1]);
    $page = Livewire::test(MediaItemsRecordForm::class, ['record' => $post]);
    $key = array_key_first($page->get('data.items'));

    $page->set("data.items.{$key}.images", [UploadedFile::fake()->create('row.txt', 1)])
        ->call('autosave')->assertDispatched('autosave-status', status: 'saved');

    expect($item->fresh()->getMedia())->toHaveCount(1);
});

test('an invalid upload is reported as pending while other fields save', function () {
    $post = UploadPost::create(['title' => 'Original']);

    Livewire::test(EditUploadPost::class, ['record' => $post->getKey()])
        ->set('data.settings', [UploadedFile::fake()->create('oversize.txt', 20)])
        ->set('data.title', 'Changed')
        ->call('autosave')
        ->assertDispatched('autosave-status', fn (string $event, array $params): bool => in_array('settings', $params['pending'] ?? [], true));

    expect($post->fresh()->title)->toBe('Changed');
});

test('spatie media files are registered in the upload ledger until the database commit completes', function () {
    $post = UploadPost::create(['title' => 'Original']);
    $page = Livewire::test(LedgerSpyEditUploadPost::class, ['record' => $post->getKey()]);

    $page->set('data.gallery', [UploadedFile::fake()->create('media.txt', 1)])
        ->call('autosave')->assertDispatched('autosave-status', status: 'saved');

    expect($page->instance()->ledgerDuringSave)->not->toBeNull()->not->toBeEmpty();
    expect(Storage::disk('public')->allFiles())->not->toBeEmpty();
    expect(Cache::get('filament-autosave:upload-ledger'))->toBeNull();
});

test('a failure after spatie media is written removes the file and forgets its ledger token', function () {
    $post = UploadPost::create(['title' => 'Original']);

    Livewire::test(FailingAfterSaveUploadPost::class, ['record' => $post->getKey()])
        ->set('data.gallery', [UploadedFile::fake()->create('media.txt', 1)])
        ->call('autosave')->assertDispatched('autosave-status', status: 'error');

    expect($post->fresh()->getMedia())->toHaveCount(0);
    expect(Cache::get('filament-autosave:upload-ledger'))->toBeNull();
});

test('an untouched FileUpload does not disable undo for a column-only edit page autosave', function () {
    $post = UploadPost::create(['title' => 'Original']);

    Livewire::test(EditUploadPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Changed')
        ->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved')
        ->assertSet('autosaveCanUndo', true)
        ->call('undoAutosave');

    expect($post->fresh()->title)->toBe('Original');
});

test('a changed FileUpload still withholds undo even when a column changes alongside it', function () {
    $post = UploadPost::create(['title' => 'Original']);

    Livewire::test(EditUploadPost::class, ['record' => $post->getKey()])
        ->set('data.title', 'Changed')
        ->set('data.settings', [UploadedFile::fake()->create('document.txt', 1)])
        ->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved')
        ->assertSet('autosaveCanUndo', false);
});

test('a spatie media file is in the upload ledger the moment its row is created, before it reaches disk', function () {
    $post = UploadPost::create(['title' => 'Original']);
    $observed = [];
    $page = Livewire::test(EditUploadPost::class, ['record' => $post->getKey()]);

    // Spatie saves the `media` row first and copies the file afterwards, so
    // this fires in the window a killed process would leave the file behind.
    // Registered after mount, like any host listener would be: the package
    // registers its own at mount, so it runs first.
    Media::created(function (Media $media) use (&$observed): void {
        $path = $media->getPathRelativeToRoot();
        $entries = Cache::get('filament-autosave:upload-ledger') ?? [];
        $journaled = collect($entries)->pluck('files')->flatten(1)
            ->contains(fn (array $file): bool => $file['path'] === $path);

        $observed[] = ['journaled' => $journaled, 'on_disk' => Storage::disk('public')->exists($path)];
    });

    $page->set('data.gallery', [UploadedFile::fake()->create('media.txt', 1)])
        ->call('autosave')->assertDispatched('autosave-status', status: 'saved');

    expect($observed)->toHaveCount(1)
        ->and($observed[0]['on_disk'])->toBeFalse()
        ->and($observed[0]['journaled'])->toBeTrue();

    expect(Cache::get('filament-autosave:upload-ledger'))->toBeNull()
        ->and($post->fresh()->getMedia())->toHaveCount(1);
// --- Spatie media inside a JSON (non-relationship) repeater, one collection per row ---

function rowMediaPost(array $rows): UploadPost
{
    $settings = [];

    foreach ($rows as $uuid => $label) {
        $settings[] = ['uuid' => $uuid, 'label' => $label];
    }

    return UploadPost::create(['title' => 'Original', 'settings' => $settings]);
}

function rowKeys(object $page): array
{
    return array_keys($page->get('data.settings'));
}

test('media in a per-row collection is saved for each JSON repeater row without touching the others', function () {
    $post = rowMediaPost(['aaa' => 'A', 'bbb' => 'B']);
    $page = Livewire::test(EditRowMediaPost::class, ['record' => $post->getKey()]);
    [$rowA, $rowB] = rowKeys($page);

    $page->set("data.settings.{$rowA}.images", [UploadedFile::fake()->create('a.txt', 1)])
        ->set("data.settings.{$rowB}.images", [UploadedFile::fake()->create('b.txt', 1)])
        ->call('autosave')->assertDispatched('autosave-status', status: 'saved');

    expect($post->fresh()->getMedia('row_aaa'))->toHaveCount(1)
        ->and($post->fresh()->getMedia('row_bbb'))->toHaveCount(1)
        ->and($post->fresh()->getMedia('default'))->toHaveCount(0);
});

test('editing one JSON repeater row leaves the media of the other rows intact', function () {
    $post = rowMediaPost(['aaa' => 'A', 'bbb' => 'B']);
    $post->addMediaFromString('b')->usingFileName('b.txt')->toMediaCollection('row_bbb', 'public');
    $page = Livewire::test(EditRowMediaPost::class, ['record' => $post->getKey()]);
    [$rowA] = rowKeys($page);

    $page->set("data.settings.{$rowA}.label", 'A changed')
        ->set("data.settings.{$rowA}.images", [UploadedFile::fake()->create('a.txt', 1)])
        ->call('autosave')->assertDispatched('autosave-status', status: 'saved');

    expect($post->fresh()->getMedia('row_bbb')->pluck('file_name')->all())->toBe(['b.txt'])
        ->and($post->fresh()->getMedia('row_aaa'))->toHaveCount(1)
        ->and(collect($post->fresh()->settings)->firstWhere('uuid', 'aaa')['label'])->toBe('A changed');
});

test('reordering JSON repeater rows keeps each media collection with its row uuid', function () {
    $post = rowMediaPost(['aaa' => 'A', 'bbb' => 'B']);
    $post->addMediaFromString('a')->usingFileName('a.txt')->toMediaCollection('row_aaa', 'public');
    $post->addMediaFromString('b')->usingFileName('b.txt')->toMediaCollection('row_bbb', 'public');
    $page = Livewire::test(EditRowMediaPost::class, ['record' => $post->getKey()]);
    $rows = $page->get('data.settings');
    [$rowA, $rowB] = array_keys($rows);

    $page->set('data.settings', [$rowB => $rows[$rowB], $rowA => $rows[$rowA]])
        ->call('autosave')->assertDispatched('autosave-status', status: 'saved');

    expect(array_column($post->fresh()->settings, 'uuid'))->toBe(['bbb', 'aaa'])
        ->and($post->fresh()->getMedia('row_aaa')->pluck('file_name')->all())->toBe(['a.txt'])
        ->and($post->fresh()->getMedia('row_bbb')->pluck('file_name')->all())->toBe(['b.txt']);
});

test('a new JSON repeater row with media gets its own fresh collection', function () {
    $post = rowMediaPost(['aaa' => 'A']);
    $page = Livewire::test(EditRowMediaPost::class, ['record' => $post->getKey()]);
    $rows = $page->get('data.settings');
    $rows['new-row'] = ['uuid' => 'ccc', 'label' => 'C', 'images' => []];

    $page->set('data.settings', $rows)
        ->set('data.settings.new-row.images', [UploadedFile::fake()->create('c.txt', 1)])
        ->call('autosave')->assertDispatched('autosave-status', status: 'saved');

    expect($post->fresh()->getMedia('row_ccc'))->toHaveCount(1)
        ->and(array_column($post->fresh()->settings, 'uuid'))->toBe(['aaa', 'ccc']);
});

test('a shared collection across JSON repeater rows stays blocked and is reported as pending', function () {
    $post = rowMediaPost(['aaa' => 'A', 'bbb' => 'B']);
    $post->addMediaFromString('kept')->usingFileName('kept.txt')->toMediaCollection('gallery', 'public');
    $page = Livewire::test(EditSharedRowMediaPost::class, ['record' => $post->getKey()]);
    [$rowA] = rowKeys($page);

    $page->set("data.settings.{$rowA}.images", [UploadedFile::fake()->create('a.txt', 1)])
        ->set('data.title', 'Changed')
        ->call('autosave')
        ->assertDispatched('autosave-status', fn (string $event, array $params): bool => in_array('settings', $params['pending'] ?? [], true));

    expect($post->fresh()->title)->toBe('Changed')
        ->and($post->fresh()->getMedia('gallery')->pluck('file_name')->all())->toBe(['kept.txt']);
});

test('a failure after a per-row media write cleans only the affected row collection', function () {
    $post = rowMediaPost(['aaa' => 'A', 'bbb' => 'B']);
    $post->addMediaFromString('a')->usingFileName('a-existing.txt')->toMediaCollection('row_aaa', 'public');
    $post->addMediaFromString('b')->usingFileName('b.txt')->toMediaCollection('row_bbb', 'public');
    $page = Livewire::test(FailingAfterSaveRowMediaPost::class, ['record' => $post->getKey()]);
    [$rowA] = rowKeys($page);
    // Add a file next to the existing media uuid instead of replacing the row
    // state, so the existing file is not abandoned by the row's component.
    $page->set("data.settings.{$rowA}.images.new", UploadedFile::fake()->create('a-new.txt', 1))
        ->call('autosave')->assertDispatched('autosave-status', status: 'error');

    expect($post->fresh()->getMedia('row_aaa')->pluck('file_name')->all())->toBe(['a-existing.txt'])
        ->and($post->fresh()->getMedia('row_bbb')->pluck('file_name')->all())->toBe(['b.txt']);
});
