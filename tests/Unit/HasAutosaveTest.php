<?php

use Filament\Resources\Events\RecordSaved;
use Filament\Resources\Events\RecordUpdated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Lenorix\FilamentAutosave\HasAutosave;
use Lenorix\FilamentAutosave\HasAutosaveBase;
use Lenorix\FilamentAutosave\Tests\Fixtures\AutosaveCreateFormComponent;
use Lenorix\FilamentAutosave\Tests\Fixtures\DisabledEditPage;
use Lenorix\FilamentAutosave\Tests\Fixtures\FakeEditPage;
use Lenorix\FilamentAutosave\Tests\Fixtures\FakeFormState;
use Lenorix\FilamentAutosave\Tests\Fixtures\FakeRecord;
use Lenorix\FilamentAutosave\Tests\Fixtures\OverridingEditPage;
use Livewire\Attributes\Locked;

beforeEach(function () {
    Cache::flush();
});

test('autosave starts out enabled', function () {
    expect(makeEditPage()->isAutosaveEnabled())->toBeTrue();
});

test('dirty-only updates are enabled by default', function () {
    expect(config('filament-autosave.dirty_only'))->toBeTrue();
});

test('clean-field refresh is enabled by default', function () {
    expect(config('filament-autosave.refresh_unchanged_fields'))->toBeTrue();
});

test('relationship undo depth ignores wildcard row indexes', function () {
    $component = new class
    {
        use HasAutosaveBase;

        public function dispatch(string $event, ...$params): void {}
    };

    expect((fn (string $path): int => $this->autosaveRelationshipUndoDepth($path))
        ->call($component, 'settings.items.*.subitems.*.category_id'))
        ->toBe(4);
});

test('undo snapshots and restores morphTo foreign keys', function () {
    $parent = Mockery::mock(Model::class);
    $parent->shouldReceive('getAttribute')->with('featured_type')->andReturn('category');
    $parent->shouldReceive('getAttribute')->with('featured_id')->andReturn(7);
    $parent->shouldReceive('forceFill')->with([
        'featured_type' => 'category',
        'featured_id' => 7,
    ])->andReturnSelf();
    $parent->shouldReceive('save')->once();

    $relation = Mockery::mock(MorphTo::class);
    $relation->shouldReceive('getParent')->andReturn($parent);
    $relation->shouldReceive('getMorphType')->andReturn('featured_type');
    $relation->shouldReceive('getForeignKeyName')->andReturn('featured_id');

    $field = new class($relation)
    {
        public function __construct(private object $relation) {}

        public function getRelationship(): object
        {
            return $this->relation;
        }
    };

    $page = new class($field)
    {
        use HasAutosave;

        public function __construct(public object $field) {}

        protected function autosaveRelationshipFields(): array
        {
            return ['featured' => [$this->field]];
        }
    };

    $snapshot = (fn ($relationships) => $this->captureAutosaveRelationshipUndo($relationships))
        ->call($page, ['featured' => [$field]]);

    expect($snapshot)->toBe([
        'featured' => [
            'type' => 'morphTo',
            'attributes' => [
                'featured_type' => 'category',
                'featured_id' => 7,
            ],
        ],
    ]);

    (fn ($value) => $this->restoreAutosaveRelationshipUndo($value))->call($page, $snapshot);
});

test('belongsTo fields in repeated rows all contribute their concrete state', function () {
    $relation = Mockery::mock(BelongsTo::class);
    $makeField = static function (string $path, int $value) use ($relation): object {
        return new class($path, $value, $relation)
        {
            public function __construct(private string $path, private int $value, private object $relation) {}

            public function getStatePath(): string
            {
                return 'data.'.$this->path;
            }

            public function getRawState(): int
            {
                return $this->value;
            }

            public function getRelationship(): object
            {
                return $this->relation;
            }
        };
    };

    $first = $makeField('items.first.category_id', 3);
    $second = $makeField('items.second.category_id', 5);

    $page = new class($first, $second)
    {
        use HasAutosave;

        public FakeFormState $form;

        public ?array $data = [];

        public function __construct(public object $first, public object $second)
        {
            $this->form = new FakeFormState([]);
        }

        protected function getAutosaveFields(): array
        {
            return ['items.*.category_id' => [$this->first, $this->second]];
        }
    };

    $data = (fn (): array => $this->getAutosaveData())->call($page);

    expect(data_get($data, 'items.first.category_id'))->toBe(3)
        ->and(data_get($data, 'items.second.category_id'))->toBe(5);
});

test('disabled edit autosave leaves the record unwritten', function () {
    $page = makeEditPage(['title' => 'Original']);
    $page->autosaveEnabled = false;

    $page->form->setState(['title' => 'Changed']);
    $page->autosave();

    expect($page->updates)->toBeEmpty();
});

test('debounce adopts the configured value by default', function () {
    config(['filament-autosave.debounce' => 2000]);

    expect(makeEditPage()->getAutosaveDebounce())->toBe(2000);
});

test('page settings take precedence over the configured debounce', function () {
    config(['filament-autosave.debounce' => 1500]);

    expect((new OverridingEditPage)->getAutosaveDebounce())->toBe(3000);
});

test('undo lifetime adopts the configured value by default', function () {
    config(['filament-autosave.undo_ttl' => 45]);

    expect((fn () => $this->getUndoTtlMinutes())->call(makeEditPage()))->toBe(45);
});

test('undo lifetime defaults to ninety minutes without configuration', function () {
    expect((fn () => $this->getUndoTtlMinutes())->call(makeEditPage()))->toBe(90);
});

test('clearing undo snapshots removes column, relationship, and expected caches', function () {
    $page = new class extends FakeEditPage
    {
        protected function getUndoCacheKey(string $suffix = ''): string
        {
            return $suffix === '' ? 'test:undo' : 'test:undo:'.$suffix;
        }
    };

    $keys = [
        'test:undo',
        'test:undo:relationships',
        'test:undo:expected',
        'test:undo:expected-relationships',
        'test:undo:external',
        'test:undo:expected-external',
    ];

    foreach ($keys as $key) {
        Cache::put($key, ['value' => true]);
    }

    (fn () => $this->clearUndoSnapshots())->call($page);

    foreach ($keys as $key) {
        expect(Cache::get($key))->toBeNull();
    }
});

test('exclusion lists merge configuration with page settings', function () {
    config(['filament-autosave.except' => ['token']]);

    expect((new OverridingEditPage)->getAutosaveExcept())
        ->toContain('token')
        ->toContain('secret_note');
});

test('mounting surfaces the resolved debounce to the indicator', function () {
    config(['filament-autosave.debounce' => 1750]);

    $page = new OverridingEditPage;
    $page->mountHasAutosave();

    expect($page->autosaveDebounceMs)->toBe(3000);
});

test('mounting exposes the form state path to the browser controller', function () {
    $page = new class
    {
        use HasAutosave;

        public object $form;

        public ?array $formState = ['title' => 'Original'];

        public function __construct()
        {
            $this->form = new class($this)
            {
                public function __construct(private object $page) {}

                public function getStatePath(): string
                {
                    return 'formState';
                }

                public function getRawState(): array
                {
                    return $this->page->formState;
                }

                public function fill(array $state): void
                {
                    $this->page->formState = $state;
                }
            };
        }
    };

    $page->mountHasAutosave();

    expect($page->autosaveDataPath)->toBe('formState');
});

test('shouldAutosave turns autosave off on the server and in the browser', function () {
    $page = new DisabledEditPage;
    $page->mountHasAutosave();

    expect($page->autosaveEnabled)->toBeFalse()
        ->and($page->isAutosaveEnabled())->toBeFalse();

    $page->form->setState(['title' => 'Changed']);
    $page->autosave();

    expect($page->updates)->toBeEmpty();
});

test('the browser cannot flip a page-level autosave restriction back on', function () {
    $page = new DisabledEditPage;
    $page->mountHasAutosave();

    $page->autosaveEnabled = true;

    $page->form->setState(['title' => 'Changed']);
    $page->autosave();

    expect($page->updates)->toBeEmpty();
});

test('pages may define autosave settings without clashing with trait properties', function () {
    // A conflicting trait property redeclaration causes a fatal error.
    $traitProperties = array_map(
        fn (ReflectionProperty $property) => $property->getName(),
        (new ReflectionClass(HasAutosaveBase::class))->getProperties(),
    );

    expect($traitProperties)
        ->not->toContain('autosaveDebounce')
        ->not->toContain('autosaveExcept');

    $page = new class extends FakeEditPage
    {
        protected int $autosaveDebounce = 4000;

        /** @var array<string> */
        protected array $autosaveExcept = ['nope'];
    };

    expect($page->getAutosaveDebounce())->toBeInt();
});

test('every public Livewire property added by the traits is locked', function (string $class, array $properties) {
    foreach ($properties as $property) {
        expect((new ReflectionProperty($class, $property))->getAttributes(Locked::class))
            ->not->toBeEmpty("{$class}::\${$property} must be #[Locked]");
    }
})->with([
    [FakeEditPage::class, ['autosaveEnabled', 'autosaveSnapshotHash', 'autosaveDebounceMs', 'autosaveDataPath', 'autosaveValidationErrors', 'autosaveValidationKeys', 'autosaveCanUndo']],
    [AutosaveCreateFormComponent::class, ['autosaveEnabled', 'autosaveSnapshotHash', 'autosaveDebounceMs', 'autosaveDataPath', 'autosaveHasDraft']],
]);

test('mounting seeds the autosave snapshot hash', function () {
    $page = makeEditPage(['name' => 'John']);

    expect($page->autosaveSnapshotHash)->toBe('');

    $page->mountHasAutosave();

    expect($page->autosaveSnapshotHash)->not->toBe('');
});

test('edit autosave reports idle when nothing changed', function () {
    $page = makeEditPage(['name' => 'John']);

    $page->mountHasAutosave();
    $page->autosave();

    expect($page->updates)->toBeEmpty();
    expect($page->dispatched)->toHaveCount(1);
    expect($page->dispatched[0]['params'])->toHaveKey('status', 'idle');
});

test('edit autosave routes changed values through handleRecordUpdate', function () {
    $page = makeEditPage(['title' => 'Original']);
    $page->mountHasAutosave();

    $page->form->setState(['title' => 'Updated']);
    $page->autosave();

    expect($page->updates)->toHaveCount(1);
    expect($page->updates[0])->toBe(['title' => 'Updated']);
    expect($page->dispatched[0]['params'])->toHaveKey('status', 'saved');
});

test('dirty-only autosave writes just the fields changed since mount', function () {
    $page = makeEditPage(['title' => 'Original', 'slug' => 'original']);
    $page->mountHasAutosave();

    $page->form->setState(['title' => 'Updated', 'slug' => 'original']);
    $page->autosave();

    expect($page->updates)->toBe([['title' => 'Updated']]);
});

test('dirty-only autosave keeps unchanged fields dirty until they change or are saved', function () {
    $page = makeEditPage(['title' => 'Original', 'slug' => 'original']);
    $page->mountHasAutosave();

    $page->form->setState(['title' => 'Updated', 'slug' => 'original']);
    $page->autosave();
    $page->form->setState(['title' => 'Updated', 'slug' => 'changed']);
    $page->autosave();

    expect($page->updates)->toBe([
        ['title' => 'Updated'],
        ['slug' => 'changed'],
    ]);
});

test('dirty-only autosave can be disabled to preserve full payload writes', function () {
    config(['filament-autosave.dirty_only' => false]);

    $page = makeEditPage(['title' => 'Original', 'slug' => 'original']);
    $page->mountHasAutosave();
    $page->form->setState(['title' => 'Updated', 'slug' => 'original']);
    $page->autosave();

    expect($page->updates)->toBe([['title' => 'Updated', 'slug' => 'original']]);
});

test('edit autosave strips excluded fields ahead of the write', function () {
    $page = makeEditPage(['title' => 'A', 'secret_note' => 'secret']);
    $page->exceptFields = ['secret_note'];
    $page->mountHasAutosave();

    $page->form->setState(['title' => 'B', 'secret_note' => 'newsecret']);
    $page->autosave();

    expect($page->updates[0])->toBe(['title' => 'B']);
});

test('a clean edit autosave refreshes the snapshot hash', function () {
    $page = makeEditPage(['title' => 'A']);
    $page->mountHasAutosave();
    $originalHash = $page->autosaveSnapshotHash;

    $page->form->setState(['title' => 'B']);
    $page->autosave();

    expect($page->autosaveSnapshotHash)->not->toBe($originalHash);
});

test("edit autosave snapshots the record's pre-save values for undo", function () {
    $page = makeEditPage(['title' => 'Original'], ['title' => 'Original']);
    $page->mountHasAutosave();

    $page->form->setState(['title' => 'Updated']);
    $page->autosave();

    expect($page->autosaveCanUndo)->toBeTrue();
});

test('edit autosave offers no undo when there is nothing to revert to', function () {
    $page = makeEditPage(['title' => 'Original']);
    $page->mountHasAutosave();

    $page->form->setState(['title' => 'Updated']);
    $page->autosave();

    expect($page->updates)->toHaveCount(1);
    expect($page->autosaveCanUndo)->toBeFalse();
});

test('undo revives the earlier values and clears the snapshot', function () {
    $page = makeEditPage(['title' => 'Original'], ['title' => 'Original']);
    $page->mountHasAutosave();

    $page->form->setState(['title' => 'Updated']);
    $page->autosave();

    $page->undoAutosave();

    expect($page->updates)->toHaveCount(2);
    expect($page->updates[1])->toBe(['title' => 'Original']);
    expect($page->autosaveCanUndo)->toBeFalse();
    expect(end($page->dispatched)['params'])->toHaveKey('status', 'undone');
});

test('undo refuses to overwrite a field changed by another user', function () {
    $page = makeEditPage(['title' => 'Original'], ['title' => 'Original']);
    $page->mountHasAutosave();
    $page->form->setState(['title' => 'Saved']);
    $page->autosave();

    $page->simulateConcurrentUpdate(['title' => 'Changed elsewhere']);
    $page->undoAutosave();

    expect($page->updates)->toBe([['title' => 'Saved']])
        ->and($page->autosaveCanUndo)->toBeFalse()
        ->and(lastStatus($page))->toBe('conflict')
        ->and($page->currentDbState())->toBe(['title' => 'Changed elsewhere']);
});

test('undo still restores its field when another user changed a different field', function () {
    $page = makeEditPage(
        ['title' => 'Original', 'slug' => 'original'],
        ['title' => 'Original', 'slug' => 'original'],
    );
    $page->mountHasAutosave();
    $page->form->setState(['title' => 'Saved', 'slug' => 'original']);
    $page->autosave();

    $page->simulateConcurrentUpdate(['slug' => 'changed elsewhere']);
    $page->undoAutosave();

    expect($page->updates)->toBe([
        ['title' => 'Saved'],
        ['title' => 'Original'],
    ])->and(lastStatus($page))->toBe('undone');
});

test('undo touches nothing when no snapshot is stored', function () {
    $page = makeEditPage(['title' => 'Same'], ['title' => 'Same']);
    $page->mountHasAutosave();

    $page->undoAutosave();

    expect($page->updates)->toBeEmpty();
    expect($page->autosaveCanUndo)->toBeFalse();
});

test('undo copes with snapshot values that refuse JSON encoding', function () {
    $page = makeEditPage(['title' => 'Original'], ['title' => "\xB1\x31"]);
    $page->mountHasAutosave();

    $page->form->setState(['title' => 'Updated']);
    $page->autosave();

    expect($page->autosaveCanUndo)->toBeTrue();
});

test('undo dismisses snapshots left by a different page instance', function () {
    $page = makeEditPage(['title' => 'Current'], ['title' => 'Current']);
    $page->mountHasAutosave();

    Cache::put(
        (fn () => $this->getUndoCacheKey())->call($page),
        ['title' => 'Ancient'],
        now()->addMinutes(5),
    );

    $page->undoAutosave();

    expect($page->updates)->toBeEmpty();
    expect(end($page->dispatched)['params'])->toHaveKey('status', 'idle');
});

test('undo reports idle when the snapshot is missing', function () {
    $page = makeEditPage(['title' => 'Same'], ['title' => 'Same']);
    $page->mountHasAutosave();

    $page->undoAutosave();

    expect($page->autosaveCanUndo)->toBeFalse();
    expect($page->dispatched)->toHaveCount(1);
    expect($page->dispatched[0]['params'])->toHaveKey('status', 'idle');
});

test('edit autosave surfaces an error when the write throws', function () {
    $page = new class
    {
        use HasAutosave;

        public ?array $data = [];

        public FakeFormState $form;

        public array $dispatched = [];

        public function __construct()
        {
            $this->form = new FakeFormState(['title' => 'X']);
        }

        public function dispatch(string $event, ...$params): void
        {
            $this->dispatched[] = ['event' => $event, 'params' => $params];
        }

        public function authorizeAccess(): void {}

        public function getRecord(): object
        {
            return new FakeRecord;
        }

        /** @param  array<string, mixed>  $data */
        public function handleRecordUpdate(object $record, array $data): object
        {
            throw new RuntimeException('db down');
        }
    };

    $page->autosave();

    expect($page->dispatched[0]['params'])->toHaveKey('status', 'error');
});

test('undo snapshots include only genuine record columns', function () {
    $page = new class
    {
        use HasAutosave;

        public FakeFormState $form;

        public ?array $data = [];

        public array $dispatched = [];

        public array $updates = [];

        public function __construct()
        {
            $this->form = new FakeFormState(['title' => 'New', 'author' => 'x']);
        }

        public function dispatch(string $event, ...$params): void
        {
            $this->dispatched[] = ['event' => $event, 'params' => $params];
        }

        public function authorizeAccess(): void {}

        public function getRecord(): object
        {
            return new FakeRecord(['title' => 'Old']);
        }

        /** @param  array<string, mixed>  $data */
        public function handleRecordUpdate(object $record, array $data): object
        {
            $this->updates[] = $data;

            return $record;
        }
    };

    $page->autosave();
    $page->undoAutosave();

    expect($page->updates[1])->toBe(['title' => 'Old']);
});

test('a failed autosave logs the exception class, leaking no field values', function () {
    Log::spy();

    $page = new class
    {
        use HasAutosave;

        public ?array $data = [];

        public FakeFormState $form;

        public array $dispatched = [];

        public function __construct()
        {
            $this->form = new FakeFormState(['title' => 'PII-LEAK-XYZ']);
        }

        public function dispatch(string $event, ...$params): void
        {
            $this->dispatched[] = ['event' => $event, 'params' => $params];
        }

        public function authorizeAccess(): void {}

        public function getRecord(): object
        {
            return new FakeRecord;
        }

        /** @param  array<string, mixed>  $data */
        public function handleRecordUpdate(object $record, array $data): object
        {
            throw new RuntimeException('SQLSTATE[23000] value='.($data['title'] ?? ''));
        }
    };

    $page->autosave();

    expect($page->dispatched[0]['params'])->toHaveKey('status', 'error');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context = []) => ! str_contains($message.json_encode($context), 'PII-LEAK-XYZ'))
        ->once();
});

test('a page denying access blocks both saves and undo', function (string $action) {
    $page = new class(['title' => 'Original'], ['title' => 'Original']) extends FakeEditPage
    {
        public function authorizeAccess(): void
        {
            throw new RuntimeException('Access denied');
        }
    };
    $page->mountHasAutosave();
    $page->form->setState(['title' => 'Changed']);
    (fn () => $this->storeUndoSnapshot(['title']))->call($page);
    $page->autosaveCanUndo = true;
    $page->{$action}();

    expect($page->updates)->toBeEmpty();
    expect(end($page->dispatched)['params']['status'])->toBe('error');
})->with(['autosave', 'undoAutosave']);

test('an expired undo snapshot leaves the record untouched', function () {
    $this->freezeTime();
    $page = makeEditPage(['title' => 'Original'], ['title' => 'Original']);
    $page->mountHasAutosave();
    $page->form->setState(['title' => 'Saved']);
    $page->autosave();
    expect($page->autosaveCanUndo)->toBeTrue();
    $updates = $page->updates;

    $this->travel(91)->minutes();
    $page->undoAutosave();

    expect($page->updates)->toBe($updates)
        ->and($page->autosaveCanUndo)->toBeFalse();
    expect(end($page->dispatched)['params']['status'])->toBe('idle');
});

test('dispatching record events does not require a real Eloquent model', function () {
    Event::fake([RecordUpdated::class, RecordSaved::class]);

    $page = makeEditPage(['title' => 'Original'], ['title' => 'Original']);
    $page->mountHasAutosave();
    $page->form->setState(['title' => 'Saved']);

    $page->autosave();

    expect($page->autosaveCanUndo)->toBeTrue();
    Event::assertDispatched(RecordUpdated::class);
    Event::assertDispatched(RecordSaved::class);
});
