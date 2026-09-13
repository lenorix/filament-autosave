<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Lenorix\FilamentAutosave\AutosaveManager;
use Lenorix\FilamentAutosave\HasAutosaveForCreate;
use Lenorix\FilamentAutosave\Tests\Fixtures\FakeCreateRecordBase;

beforeEach(function () {
    Cache::flush();
});

function makeCreateRecordPage(): object
{
    return new class extends FakeCreateRecordBase
    {
        use HasAutosaveForCreate;

        public ?array $data = [];

        /** @var array<int, array<string, mixed>> */
        public array $dispatched = [];

        public function dispatch(string $event, ...$params): void
        {
            $this->dispatched[] = ['event' => $event, 'params' => $params];
        }
    };
}

function seedDraft(object $page): void
{
    Cache::put(AutosaveManager::cacheKey(get_class($page)), ['title' => 'Draft'], 3600);
    $page->autosaveHasDraft = true;
}

function pageDraft(object $page): ?array
{
    return Cache::get(AutosaveManager::cacheKey(get_class($page)));
}

test('a successful create wipes the draft', function () {
    $page = makeCreateRecordPage();
    seedDraft($page);

    $page->create();

    expect(pageDraft($page))->toBeNull();
    expect($page->autosaveHasDraft)->toBeFalse();
});

test('creating a record then starting another clears the draft', function () {
    $page = makeCreateRecordPage();
    seedDraft($page);

    $page->create(another: true);

    expect(pageDraft($page))->toBeNull();
    expect($page->autosaveHasDraft)->toBeFalse();
});

test('an interrupted create hangs on to the draft', function () {
    $page = makeCreateRecordPage();
    $page->shouldHalt = true;
    seedDraft($page);

    $page->create(another: true);

    expect(pageDraft($page))->toBe(['title' => 'Draft']);
});

test('an interrupted create keeps the draft even after rememberData runs at mount', function () {
    // Reset the mount flag so an interrupted create keeps its draft.
    $page = makeCreateRecordPage();
    $page->shouldHalt = true;
    seedDraft($page);

    (fn () => $this->rememberData())->call($page);

    $page->create(another: true);

    expect(pageDraft($page))->toBe(['title' => 'Draft']);
});

test('a successful create clears drafts even with a custom handleRecordCreation', function () {
    $page = new class extends FakeCreateRecordBase
    {
        use HasAutosaveForCreate;

        public ?array $data = [];

        public bool $ownCreationRan = false;

        public function dispatch(string $event, ...$params): void {}

        protected function handleRecordCreation(array $data): Model
        {
            $this->ownCreationRan = true;

            $record = new class extends Model
            {
                protected $guarded = [];
            };

            $record->exists = true;
            $record->setAttribute($record->getKeyName(), 1);

            return $record;
        }
    };

    seedDraft($page);

    $page->create(another: true);

    expect($page->ownCreationRan)->toBeTrue();
    expect(pageDraft($page))->toBeNull();
    expect($page->autosaveHasDraft)->toBeFalse();
});

test('a successful create clears drafts even with a custom afterCreate hook', function () {
    $page = new class extends FakeCreateRecordBase
    {
        use HasAutosaveForCreate;

        public ?array $data = [];

        public bool $ownAfterCreateRan = false;

        public function dispatch(string $event, ...$params): void {}

        protected function afterCreate(): void
        {
            $this->ownAfterCreateRan = true;
        }
    };

    seedDraft($page);

    $page->create(another: true);

    expect(pageDraft($page))->toBeNull();
    expect($page->autosaveHasDraft)->toBeFalse();
});

test('shouldWrapCreate is true when the base class can create records', function () {
    expect((fn () => $this->shouldWrapCreate())->call(makeCreateRecordPage()))->toBeTrue();
});

test('create is a no-op when the base class cannot create records', function () {
    $page = new class
    {
        use HasAutosaveForCreate;
    };

    $page->create();

    expect(true)->toBeTrue();
});
