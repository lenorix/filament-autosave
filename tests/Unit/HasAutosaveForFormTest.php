<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Lenorix\FilamentAutosave\AutosavePlugin;
use Lenorix\FilamentAutosave\AutosaveStore;
use Lenorix\FilamentAutosave\HasAutosaveForForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\FakeFormState;

beforeEach(function () {
    Cache::flush();
});

function makeStandaloneAutosaveForm(string $context = 'default', array $state = []): object
{
    return new class($context, $state)
    {
        use HasAutosaveForForm;

        public FakeFormState $form;

        public ?array $data = [];

        public array $dispatched = [];

        public string $context;

        public function __construct(string $context, array $state)
        {
            $this->context = $context;
            $this->form = new FakeFormState($state);
        }

        public function dispatch(string $event, ...$params): void
        {
            $this->dispatched[] = ['event' => $event, 'params' => $params];
        }

        protected function getAutosaveFormContext(): string
        {
            return $this->context;
        }
    };
}

test('standalone form components save and restore drafts', function () {
    $component = makeStandaloneAutosaveForm('profile', ['name' => 'Ada']);
    $component->mountHasAutosaveForForm();
    $component->form->setState(['name' => 'Grace']);
    $component->autosave();

    expect($component->autosaveHasDraft)->toBeTrue();

    $component->form->setState(['name' => 'Lin']);
    $component->restoreDraft();

    expect($component->form->getRawState())->toBe(['name' => 'Grace'])
        ->and(lastStatus($component))->toBe('restored');
});

test('dirty-only generic drafts merge successive field changes', function () {
    config(['filament-autosave.dirty_only' => true]);

    $first = makeStandaloneAutosaveForm('merged', ['title' => 'Old', 'slug' => 'old']);
    $first->mountHasAutosaveForForm();
    $first->form->setState(['title' => 'New title', 'slug' => 'old']);
    $first->autosave();

    $second = makeStandaloneAutosaveForm('merged', ['title' => 'New title', 'slug' => 'old']);
    $second->mountHasAutosaveForForm();
    $second->form->setState(['title' => 'New title', 'slug' => 'new-slug']);
    $second->autosave();

    $draft = app(AutosaveStore::class)->restoreDraft(
        (fn (): string => $this->getAutosaveCacheKey())->call($second),
    );

    expect($draft)->toMatchArray(['title' => 'New title', 'slug' => 'new-slug']);
});

test('a dirty-only no-op keeps the existing generic draft', function () {
    config(['filament-autosave.dirty_only' => true]);

    $component = makeStandaloneAutosaveForm('keep-draft', ['title' => 'Old']);
    $component->mountHasAutosaveForForm();
    $component->form->setState(['title' => 'New']);
    $component->autosave();
    $component->form->setState(['title' => 'New']);
    $component->autosave();

    expect($component->autosaveHasDraft)->toBeTrue()
        ->and(app(AutosaveStore::class)->restoreDraft(
            (fn (): string => $this->getAutosaveCacheKey())->call($component),
        ))->toBe(['title' => 'New']);
});

test('form contexts keep drafts isolated', function () {
    $first = makeStandaloneAutosaveForm('first', ['name' => 'Ada']);
    $second = makeStandaloneAutosaveForm('second', ['name' => 'Grace']);

    $first->mountHasAutosaveForForm();
    $first->form->setState(['name' => 'Lin']);
    $first->autosave();
    $second->mountHasAutosaveForForm();

    expect($second->autosaveHasDraft)->toBeFalse();
});

test('strict generic context mode rejects the fallback context', function () {
    config(['filament-autosave.require_form_context' => true]);

    $component = new class
    {
        use HasAutosaveForForm;

        public FakeFormState $form;

        public function __construct()
        {
            $this->form = new FakeFormState(['title' => 'Draft']);
        }

        public function dispatch(string $event, ...$params): void {}
    };

    $component->mountHasAutosaveForForm();

    expect(fn () => $component->autosave())
        ->toThrow(\LogicException::class, 'requires an explicit context');
});

test('the plugin identifies generic autosave form components', function () {
    $component = makeStandaloneAutosaveForm();
    $mode = (fn ($class) => $this->detectMode($class))->call(
        new AutosavePlugin,
        $component::class,
    );

    expect($mode)->toBe('form');
});

test('generic forms keep explicit empty values when dirty-only is enabled', function () {
    config(['filament-autosave.dirty_only' => true]);

    $record = new class extends Model
    {
        protected $guarded = [];

        public $timestamps = false;

        public function getKey(): int
        {
            return 7;
        }

        public function refresh(): static
        {
            return $this;
        }
    };
    $record->exists = true;
    $record->setRawAttributes(['name' => 'Ada', 'note' => 'old']);

    $component = new class($record)
    {
        use HasAutosaveForForm;

        public FakeFormState $form;

        public array $updates = [];

        public function __construct(public Model $record)
        {
            $this->form = new FakeFormState(['name' => 'Ada', 'note' => 'old']);
        }

        public function getRecord(): Model
        {
            return $this->record;
        }

        public function dispatch(string $event, ...$params): void {}
    };

    $component->mountHasAutosaveForForm();
    $component->form->setState(['name' => 'Grace', 'note' => null]);
    $component->autosave();

    expect($component->record->getAttributes())->toMatchArray(['name' => 'Grace', 'note' => null]);
});

test('generic forms apply Filament save mutators before persistence', function () {
    $record = new class extends Model
    {
        protected $guarded = [];

        public $timestamps = false;

        public function getKey(): int
        {
            return 8;
        }

        public function refresh(): static
        {
            return $this;
        }
    };
    $record->exists = true;
    $record->setRawAttributes(['name' => 'Ada']);

    $component = new class($record)
    {
        use HasAutosaveForForm;

        public FakeFormState $form;

        public function __construct(public Model $record)
        {
            $this->form = new FakeFormState(['name' => 'Ada']);
        }

        public function getRecord(): Model
        {
            return $this->record;
        }

        public function mutateFormDataBeforeSave(array $data): array
        {
            $data['name'] = strtoupper($data['name']);

            return $data;
        }

        public function dispatch(string $event, ...$params): void {}
    };

    $component->mountHasAutosaveForForm();
    $component->form->setState(['name' => 'Grace']);
    $component->autosave();

    expect($component->record->getAttribute('name'))->toBe('GRACE');
});

test('generic form notifications wait for the transaction commit', function () {
    Event::fake();

    $record = new class extends Model
    {
        protected $guarded = [];

        public $timestamps = false;

        public function getKey(): int
        {
            return 9;
        }

        public function refresh(): static
        {
            return $this;
        }

        public function update(array $attributes = [], array $options = []): bool
        {
            $this->setRawAttributes(array_replace($this->getAttributes(), $attributes));

            return true;
        }
    };
    $record->exists = true;
    $record->setRawAttributes(['name' => 'Ada']);

    $component = new class($record)
    {
        use HasAutosaveForForm;

        public FakeFormState $form;

        public array $log = [];

        public bool $failCommit = false;

        public function __construct(public Model $record)
        {
            $this->form = new FakeFormState(['name' => 'Ada']);
        }

        public function getRecord(): Model
        {
            return $this->record;
        }

        public function beginDatabaseTransaction(): void
        {
            $this->log[] = 'begin';
        }

        public function commitDatabaseTransaction(): void
        {
            $this->log[] = 'commit';

            if ($this->failCommit) {
                throw new RuntimeException('commit failed');
            }
        }

        public function rollBackDatabaseTransaction(): void
        {
            $this->log[] = 'rollback';
        }

        public function getSavedNotification(): object
        {
            return new class($this)
            {
                public function __construct(private object $component) {}

                public function send(): void
                {
                    $this->component->log[] = 'notification';
                }
            };
        }

        public function dispatch(string $event, ...$params): void {}
    };

    $component->mountHasAutosaveForForm();
    $component->form->setState(['name' => 'Grace']);
    $component->autosave();

    expect($component->log)->toBe(['begin', 'commit', 'notification']);
    expect($component->autosaveCanUndo)->toBeTrue();

    $hashesBeforeFailure = $component->autosaveFieldHashes;
    $component->failCommit = true;
    $component->form->setState(['name' => 'Lin']);
    $component->autosave();

    expect($component->log)->toBe(['begin', 'commit', 'notification', 'begin', 'commit', 'rollback'])
        ->and($component->autosaveFieldHashes)->toBe($hashesBeforeFailure)
        ->and($component->autosaveCanUndo)->toBeFalse();

    $undoKey = (fn (string $part): string => $this->getAutosaveFormUndoKey($part))->call($component, 'values');
    expect(Cache::has($undoKey))->toBeFalse();
});

test('form cache contexts include an owner record when no override is provided', function () {
    $owner = new class extends Model
    {
        public function getKey(): int
        {
            return 42;
        }
    };

    $component = new class($owner)
    {
        use HasAutosaveForForm;

        public function __construct(public Model $ownerRecord) {}
    };

    $context = (fn (): string => $this->getAutosaveFormContext())->call($component);

    expect($context)->toContain('owner:')->toContain('42');
});

test('generic form saves acknowledge changed relationship state', function () {
    Event::fake();

    $record = new class extends Model
    {
        protected $guarded = [];

        public $timestamps = false;

        public function getKey(): int
        {
            return 10;
        }

        public function refresh(): static
        {
            return $this;
        }

        public function update(array $attributes = [], array $options = []): bool
        {
            return true;
        }
    };
    $record->exists = true;
    $record->setRawAttributes(['title' => 'Old']);

    $field = new class
    {
        public function getRelationship(): object
        {
            return new stdClass;
        }
    };
    $form = new class
    {
        public int $relationshipSaves = 0;

        public array $state = ['tags' => [1]];

        public function getRawState(): array
        {
            return $this->state;
        }

        public function fill(array $data): void {}

        public function saveRelationships(): void
        {
            $this->relationshipSaves++;
        }
    };

    $component = new class($record, $form, $field)
    {
        use HasAutosaveForForm;

        public function __construct(public Model $record, public object $form, public object $field) {}

        public function getRecord(): Model
        {
            return $this->record;
        }

        protected function getAutosaveFields(): array
        {
            return ['tags' => [$this->field]];
        }

        public function dispatch(string $event, ...$params): void {}
    };

    $component->mountHasAutosaveForForm();
    $component->form->state = ['tags' => [2]];
    (fn () => $this->persistAutosaveFormRecord($this->record, ['tags' => [2]]))->call($component);

    expect($component->form->relationshipSaves)->toBe(1)
        ->and($component->autosaveFieldHashes['tags'])->toBe(hash('sha256', serialize([2])));
});

test('generic undo re-establishes field hashes for the restored values', function () {
    Event::fake();

    $record = new class extends Model
    {
        protected $guarded = [];

        public $timestamps = false;

        public function getKey(): int
        {
            return 11;
        }

        public function refresh(): static
        {
            return $this;
        }

        public function update(array $attributes = [], array $options = []): bool
        {
            $this->setRawAttributes(array_replace($this->getAttributes(), $attributes));

            return true;
        }
    };
    $record->exists = true;
    $record->setRawAttributes(['title' => 'Old']);

    $component = new class($record)
    {
        use HasAutosaveForForm;

        public FakeFormState $form;

        public function __construct(public Model $record)
        {
            $this->form = new FakeFormState(['title' => 'Old']);
        }

        public function getRecord(): Model
        {
            return $this->record;
        }

        public function dispatch(string $event, ...$params): void {}
    };

    $component->mountHasAutosaveForForm();
    $component->form->setState(['title' => 'New']);
    (fn () => $this->persistAutosaveFormRecord($this->record, ['title' => 'New']))->call($component);
    $component->undoAutosave();

    expect($component->form->getRawState())->toBe(['title' => 'Old'])
        ->and($component->autosaveFieldHashes['title'])->toBe(hash('sha256', serialize('Old')));
});

test('generic forms resolve mounted action and modal schemas', function () {
    $schema = new FakeFormState(['title' => 'Action value']);

    $component = new class($schema)
    {
        use HasAutosaveForForm;

        public function __construct(private FakeFormState $schema) {}

        public function getMountedActionSchema(): FakeFormState
        {
            return $this->schema;
        }
    };

    $resolved = (fn (): ?object => $this->resolveAutosaveForm())->call($component);

    expect($resolved)->toBe($schema);
});

test('generic forms resolve cached relation-manager schemas', function () {
    $schema = new FakeFormState(['body' => 'Comment']);

    $component = new class($schema)
    {
        use HasAutosaveForForm;

        public function __construct(private FakeFormState $schema) {}

        public function getSchema(string $name): FakeFormState
        {
            return $this->schema;
        }
    };

    $resolved = (fn (): ?object => $this->resolveAutosaveForm())->call($component);

    expect($resolved)->toBe($schema);
});
