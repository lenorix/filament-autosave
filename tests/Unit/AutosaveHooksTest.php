<?php

use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Events\RecordSaved;
use Filament\Resources\Events\RecordUpdated;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Lenorix\FilamentAutosave\AutosaveManager;
use Lenorix\FilamentAutosave\HasAutosaveForCreate;
use Lenorix\FilamentAutosave\Tests\Fixtures\AutosaveCreateFormComponent;
use Lenorix\FilamentAutosave\Tests\Fixtures\AutosaveEditFormComponent;
use Lenorix\FilamentAutosave\Tests\Fixtures\AutosavePasswordEditFormComponent;

beforeEach(function () {
    Cache::flush();
});

test('autosave validation evicts fields that trip custom rules', function () {
    $page = new class
    {
        use HasAutosaveForCreate;

        public ?array $data = [];

        protected function getAutosaveValidationRules(): array
        {
            return ['name' => ['max:3']];
        }
    };

    $result = (fn ($f) => $this->validateAutosaveFields($f))->call($page, [
        'name' => 'toolong',
        'title' => 'kept',
    ]);

    expect($result)->toBe(['title' => 'kept']);
});

test('autosave validation keeps fields that pass custom rules', function () {
    $page = new class
    {
        use HasAutosaveForCreate;

        public ?array $data = [];

        protected function getAutosaveValidationRules(): array
        {
            return ['name' => ['max:10']];
        }
    };

    $result = (fn ($f) => $this->validateAutosaveFields($f))->call($page, ['name' => 'ok']);

    expect($result)->toBe(['name' => 'ok']);
});

test('autosave validation enforces wildcard rules on nested fields', function () {
    $page = new class
    {
        use HasAutosaveForCreate;

        public ?array $data = [];

        protected function getAutosaveValidationRules(): array
        {
            return ['items.*.qty' => ['integer', 'min:1']];
        }
    };

    $result = (fn ($f) => $this->validateAutosaveFields($f))->call($page, [
        'items' => [['qty' => 0]],
        'title' => 'kept',
    ]);

    expect($result)->toBe(['title' => 'kept']);
});

test('autosave validation retains valid nested data', function () {
    $page = new class
    {
        use HasAutosaveForCreate;

        public ?array $data = [];

        protected function getAutosaveValidationRules(): array
        {
            return ['items.*.qty' => ['integer', 'min:1']];
        }
    };

    $result = (fn ($f) => $this->validateAutosaveFields($f))->call($page, [
        'items' => [['qty' => 3]],
    ]);

    expect($result)->toBe(['items' => [['qty' => 3]]]);
});

test('autosave respects Filament field validation limits', function () {
    $page = new class
    {
        use HasAutosaveForCreate;

        public ?array $data = [];

        protected function getAutosaveFields(): array
        {
            // Bare components have no container; Filament 4.0.x reads it
            // eagerly in isDisabled()/isHidden().
            $schema = Schema::make();

            return [
                'short_name' => [TextInput::make('short_name')->minLength(3)->container($schema)],
                'age' => [TextInput::make('age')->numeric()->maxValue(10)->container($schema)],
            ];
        }
    };

    $validate = fn (array $fields): array => $this->validateAutosaveFields($fields);

    expect($validate->call($page, [
        'short_name' => 'no',
        'age' => '11',
        'title' => 'kept',
    ]))->toBe(['title' => 'kept'])
        ->and($validate->call($page, [
            'short_name' => 'yes',
            'age' => '10',
            'title' => 'kept',
        ]))->toBe([
            'short_name' => 'yes',
            'age' => '10',
            'title' => 'kept',
        ]);
});

test('beforeAutosave reshapes draft data ahead of storage', function () {
    $page = new class extends AutosaveCreateFormComponent
    {
        protected function beforeAutosave(array $data): array
        {
            return [...$data, 'title' => strtoupper($data['title'] ?? '')];
        }
    };

    $page->mountHasAutosaveForCreate();
    $page->data = ['title' => 'hello'];
    $page->autosave();

    $draft = Cache::get(AutosaveManager::cacheKey($page::class));

    expect($draft)->toHaveKey('title', 'HELLO');
});

test('edit autosave gives hooks and mutators the complete state before dirty filtering', function () {
    $page = new class extends AutosaveEditFormComponent
    {
        public array $hookData = [];

        protected function beforeAutosave(array $data): array
        {
            $this->hookData = $data;

            return $data;
        }

        public function mutateFormDataBeforeSave(array $data): array
        {
            $data['title'] = $data['title'].'-'.$data['code'];

            return $data;
        }
    };

    $page->mountHasAutosave();
    $page->data = ['title' => 'Original', 'code' => 'stable'];
    (fn () => $this->resetAutosaveHashes())->call($page);
    $page->data['title'] = 'Changed';
    $page->autosave();

    expect($page->hookData)->toMatchArray(['title' => 'Changed', 'code' => 'STABLE'])
        ->and($page->written)->toBe(['title' => 'Changed-STABLE']);
});

test('edit autosave runs the standard lifecycle hooks and events', function () {
    Event::fake([RecordUpdated::class, RecordSaved::class]);

    $page = new class extends AutosaveEditFormComponent
    {
        public array $hooks = [];

        protected function beforeValidate(): void
        {
            $this->hooks[] = 'beforeValidate';
        }

        protected function afterValidate(): void
        {
            $this->hooks[] = 'afterValidate';
        }

        protected function beforeSave(): void
        {
            $this->hooks[] = 'beforeSave';
        }

        protected function afterSave(): void
        {
            $this->hooks[] = 'afterSave';
        }

        protected function getSavedNotification(): ?Notification
        {
            $this->hooks[] = 'notification';

            return null;
        }
    };

    $page->mountHasAutosave();
    $page->data = ['title' => 'Changed'];
    $page->autosave();

    expect($page->hooks)->toBe([
        'beforeValidate', 'afterValidate', 'beforeSave', 'afterSave', 'notification',
    ]);
    // A fake host is not a resource Page: Filament's record events are built
    // for real pages only (see AutosaveRecordEventsTest for the real objects).
    Event::assertNotDispatched(RecordUpdated::class);
    Event::assertNotDispatched(RecordSaved::class);
})->skip(fn (): bool => ! class_exists(RecordUpdated::class), 'Filament\\Resources\\Events does not exist on this Filament version (4.0.x)');

test('edit save mutation runs inside the page transaction', function () {
    $page = new class extends AutosaveEditFormComponent
    {
        public array $transaction = [];

        public function beginDatabaseTransaction(): void
        {
            $this->transaction[] = 'begin';
        }

        public function commitDatabaseTransaction(): void
        {
            $this->transaction[] = 'commit';
        }

        public function rollBackDatabaseTransaction(): void
        {
            $this->transaction[] = 'rollback';
        }

        public function mutateFormDataBeforeSave(array $data): array
        {
            expect($this->transaction)->toBe(['begin']);

            return $data;
        }
    };

    $page->mountHasAutosave();
    $page->data = ['title' => 'Changed'];
    $page->autosave();

    expect($page->transaction)->toBe(['begin', 'commit']);
});

test('invalid autosave fields remain visible while valid fields are saved', function () {
    $page = new class extends AutosaveEditFormComponent
    {
        protected function getAutosaveValidationRules(): array
        {
            return ['title' => ['max:3']];
        }
    };

    $page->mountHasAutosave();
    $page->data = ['title' => 'toolong', 'slug' => 'valid-slug'];
    $page->autosave();

    expect($page->written)->toBe(['slug' => 'valid-slug'])
        ->and($page->autosaveValidationErrors)->toHaveKey('title')
        ->and($page->autosaveValidationErrors['title'])->not->toBeEmpty();
});

test('edit autosave persists changed relationship fields separately from columns', function () {
    $relationship = new class
    {
        public string $state = 'old';

        public int $saved = 0;

        public function hasRelationship(): bool
        {
            return true;
        }

        public function getRawState(): string
        {
            return $this->state;
        }

        public function saveRelationships(): void
        {
            $this->saved++;
        }
    };

    $page = new class($relationship) extends AutosaveEditFormComponent
    {
        public function __construct(public object $relationship) {}

        protected function getAutosaveFields(): array
        {
            return ['role' => [$this->relationship]];
        }

        protected function getAutosaveData(): array
        {
            return ['title' => $this->data['title'] ?? ''];
        }
    };

    $page->mountHasAutosave();
    $page->relationship->state = 'new';
    $page->data = ['title' => 'Changed'];
    $page->autosave();

    expect($page->written)->toBe(['title' => 'Changed'])
        ->and($relationship->saved)->toBe(1)
        ->and($page->autosaveCanUndo)->toBeFalse();
});

test('a clean edit autosave calls afterAutosave', function () {
    $page = new class extends AutosaveEditFormComponent
    {
        public bool $afterRan = false;

        protected function afterAutosave(object $record): void
        {
            $this->afterRan = true;
        }
    };

    $page->mountHasAutosave();
    $page->data = ['title' => 'Changed'];
    $page->autosave();

    expect($page->afterRan)->toBeTrue();
});

test("edit autosave re-baselines Filament's change tracking once saved", function () {
    $page = new class extends AutosaveEditFormComponent
    {
        public int $rememberedCount = 0;

        protected function rememberData(): void
        {
            $this->rememberedCount++;
        }
    };

    $page->mountHasAutosave();
    $page->data = ['title' => 'Changed'];
    $page->autosave();

    expect($page->rememberedCount)->toBe(1);
});

test('edit autosave keeps change tracking intact when a filled field is excluded', function () {
    $page = new class extends AutosavePasswordEditFormComponent
    {
        public int $rememberedCount = 0;

        protected function rememberData(): void
        {
            $this->rememberedCount++;
        }
    };

    $page->mountHasAutosave();
    $page->data = ['title' => 'Changed', 'vault_key' => 'typed-secret'];
    $page->autosave();

    expect($page->written)->toHaveKey('title', 'Changed');
    expect($page->rememberedCount)->toBe(0);
});

test('edit autosave re-baselines change tracking when the excluded field is empty', function () {
    $page = new class extends AutosavePasswordEditFormComponent
    {
        public int $rememberedCount = 0;

        protected function rememberData(): void
        {
            $this->rememberedCount++;
        }
    };

    $page->mountHasAutosave();
    $page->data = ['title' => 'Changed', 'vault_key' => ''];
    $page->autosave();

    expect($page->rememberedCount)->toBe(1);
});

test("undo re-baselines Filament's change tracking after putting values back", function () {
    $page = new class extends AutosaveEditFormComponent
    {
        public int $rememberedCount = 0;

        protected function rememberData(): void
        {
            $this->rememberedCount++;
        }
    };

    $page->mountHasAutosave();
    Cache::put(
        (fn () => $this->getUndoCacheKey())->call($page),
        ['title' => 'Original'],
        now()->addMinutes(5),
    );
    $page->autosaveCanUndo = true;

    $page->undoAutosave();

    expect($page->rememberedCount)->toBe(1);
});

test("storing a create draft leaves Filament's change tracking alone", function () {
    $page = new class extends AutosaveCreateFormComponent
    {
        public int $rememberedCount = 0;

        protected function rememberData(): void
        {
            $this->rememberedCount++;
        }
    };

    $page->mountHasAutosaveForCreate();
    $page->data = ['title' => 'Drafted'];
    $page->autosave();

    expect($page->rememberedCount)->toBe(0);
});
