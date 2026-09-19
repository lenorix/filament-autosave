<?php

use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Cache;
use Lenorix\FilamentAutosave\HasAutosave;
use Lenorix\FilamentAutosave\Tests\Fixtures\FakeFormState;
use Lenorix\FilamentAutosave\Tests\Fixtures\FakeRecord;

beforeEach(function () {
    Cache::flush();
});

test('edit autosave writes records inside a database transaction', function () {
    $page = makeTransactionalEditPage();
    $page->form->setState(['title' => 'Changed']);

    $page->autosave();

    expect($page->txLog)->toBe(['begin', 'commit']);
    expect($page->updates)->toHaveCount(1);
    expect(lastStatus($page))->toBe('saved');
});

test('saved notifications are emitted after a successful commit', function () {
    $page = new class
    {
        use HasAutosave;

        public FakeFormState $form;

        public array $txLog = [];

        public function __construct()
        {
            $this->form = new FakeFormState(['title' => 'Changed']);
        }

        public function beginDatabaseTransaction(): void
        {
            $this->txLog[] = 'begin';
        }

        public function commitDatabaseTransaction(): void
        {
            $this->txLog[] = 'commit';
        }

        public function rollBackDatabaseTransaction(): void
        {
            $this->txLog[] = 'rollback';
        }

        public function authorizeAccess(): void {}

        public function dispatch(string $event, ...$params): void {}

        public function getRecord(): object
        {
            return new FakeRecord(['title' => 'Original']);
        }

        public function handleRecordUpdate(object $record, array $data): object
        {
            $this->txLog[] = 'write';

            return $record;
        }

        public function getSavedNotification(): object
        {
            return new class($this)
            {
                public function __construct(private object $page) {}

                public function send(): void
                {
                    $this->page->txLog[] = 'notification';
                }
            };
        }
    };

    $page->autosave();

    expect($page->txLog)->toBe(['begin', 'write', 'commit', 'notification']);
});

test('saved notifications are discarded when commit fails', function () {
    $page = new class
    {
        use HasAutosave;

        public FakeFormState $form;

        public array $txLog = [];

        public function __construct()
        {
            $this->form = new FakeFormState(['title' => 'Changed']);
        }

        public function beginDatabaseTransaction(): void
        {
            $this->txLog[] = 'begin';
        }

        public function commitDatabaseTransaction(): void
        {
            $this->txLog[] = 'commit';
            throw new RuntimeException('commit failed');
        }

        public function rollBackDatabaseTransaction(): void
        {
            $this->txLog[] = 'rollback';
        }

        public function authorizeAccess(): void {}

        public function dispatch(string $event, ...$params): void {}

        public function getRecord(): object
        {
            return new FakeRecord(['title' => 'Original']);
        }

        public function handleRecordUpdate(object $record, array $data): object
        {
            $this->txLog[] = 'write';

            return $record;
        }

        public function getSavedNotification(): object
        {
            return new class($this)
            {
                public function __construct(private object $page) {}

                public function send(): void
                {
                    $this->page->txLog[] = 'notification';
                }
            };
        }
    };

    $page->autosave();

    expect($page->txLog)->toBe(['begin', 'write', 'commit', 'rollback']);
});

test('edit lifecycle hooks run inside the database transaction in Filament order', function () {
    $page = new class
    {
        use HasAutosave;

        public FakeFormState $form;

        public ?array $data = [];

        public array $txLog = [];

        public function __construct()
        {
            $this->form = new FakeFormState(['title' => 'Changed']);
        }

        public function beginDatabaseTransaction(): void
        {
            $this->txLog[] = 'begin';
        }

        public function commitDatabaseTransaction(): void
        {
            $this->txLog[] = 'commit';
        }

        public function rollBackDatabaseTransaction(): void
        {
            $this->txLog[] = 'rollback';
        }

        public function authorizeAccess(): void {}

        public function dispatch(string $event, ...$params): void {}

        public function getRecord(): object
        {
            return new FakeRecord(['title' => 'Original']);
        }

        public function beforeValidate(): void
        {
            $this->txLog[] = 'beforeValidate';
        }

        public function afterValidate(): void
        {
            $this->txLog[] = 'afterValidate';
        }

        public function beforeSave(): void
        {
            $this->txLog[] = 'beforeSave';
        }

        public function handleRecordUpdate(object $record, array $data): object
        {
            $this->txLog[] = 'write';

            return $record;
        }
    };

    $page->autosave();

    expect($page->txLog)->toBe(['begin', 'beforeValidate', 'afterValidate', 'beforeSave', 'write', 'commit']);
});

test('a Halt commits or rolls back according to its rollback flag', function (bool $rollback, string $outcome) {
    $page = new class($rollback)
    {
        use HasAutosave;

        public FakeFormState $form;

        public ?array $data = [];

        public array $txLog = [];

        public bool $rollback;

        public function __construct(bool $rollback)
        {
            $this->rollback = $rollback;
            $this->form = new FakeFormState(['title' => 'Changed']);
        }

        public function beginDatabaseTransaction(): void
        {
            $this->txLog[] = 'begin';
        }

        public function commitDatabaseTransaction(): void
        {
            $this->txLog[] = 'commit';
        }

        public function rollBackDatabaseTransaction(): void
        {
            $this->txLog[] = 'rollback';
        }

        public function authorizeAccess(): void {}

        public function dispatch(string $event, ...$params): void {}

        public function getRecord(): object
        {
            return new FakeRecord(['title' => 'Original']);
        }

        public function beforeSave(): void
        {
            throw (new Halt)->rollBackDatabaseTransaction($this->rollback);
        }

        public function handleRecordUpdate(object $record, array $data): object
        {
            return $record;
        }
    };

    $page->autosave();

    expect($page->txLog)->toBe(['begin', $outcome]);
})->with([[true, 'rollback'], [false, 'commit']]);

test('a failed edit autosave rolls back and flags an error', function () {
    $page = makeTransactionalEditPage(failWrite: true);
    $page->form->setState(['title' => 'Changed']);

    $page->autosave();

    expect($page->txLog)->toBe(['begin', 'rollback']);
    expect(lastStatus($page))->toBe('error');
});

test('a failed write clears the undo snapshot prepared for that transaction', function () {
    $page = makeTransactionalEditPage(failWrite: true);
    $page->form->setState(['title' => 'Changed']);

    $page->autosave();

    // Every part of the Undo target, not only the column snapshot.
    $keys = (fn () => $this->autosaveUndo()->keys())->call($page);

    expect($page->autosaveCanUndo)->toBeFalse();

    foreach ($keys as $key) {
        expect(Cache::has($key))->toBeFalse();
    }
});

test('undo puts record values back inside a database transaction', function () {
    $page = makeTransactionalEditPage();

    $undoKey = (fn () => $this->getUndoCacheKey())->call($page);
    Cache::put($undoKey, ['title' => 'previous'], now()->addMinutes(30));
    $page->autosaveCanUndo = true;

    $page->undoAutosave();

    expect($page->txLog)->toBe(['begin', 'commit']);
    expect($page->updates)->toHaveCount(1);
    expect($page->updates[0])->toBe(['title' => 'previous']);
});

test('edit autosave works on pages lacking transaction helpers', function () {
    $page = new class
    {
        use HasAutosave;

        public FakeFormState $form;

        public ?array $data = [];

        /** @var array<int, array<string, mixed>> */
        public array $dispatched = [];

        /** @var array<int, array<string, mixed>> */
        public array $updates = [];

        public function __construct()
        {
            $this->form = new FakeFormState;
        }

        public function dispatch(string $event, ...$params): void
        {
            $this->dispatched[] = ['event' => $event, 'params' => $params];
        }

        public function getRecord(): object
        {
            return new FakeRecord(['title' => 'old']);
        }

        /** @param  array<string, mixed>  $data */
        public function handleRecordUpdate(object $record, array $data): object
        {
            $this->updates[] = $data;

            return $record;
        }
    };

    $page->form->fill(['title' => 'Changed']);
    $page->autosave();

    expect($page->updates)->toHaveCount(1);
});
