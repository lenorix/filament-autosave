<?php

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Contracts\Auth\Guard;
use Lenorix\FilamentAutosave\AutosavePlugin;
use Lenorix\FilamentAutosave\HasAutosave;
use Lenorix\FilamentAutosave\Tests\Fixtures\FakeCreatePage;
use Lenorix\FilamentAutosave\Tests\Fixtures\FakeEditPage;
use Lenorix\FilamentAutosave\Tests\Fixtures\FakeFormState;
use Lenorix\FilamentAutosave\Tests\Fixtures\FakeRecord;

/**
 * Stand in for a Filament panel so the cache scope can be resolved.
 */
function fakeFilamentPanel(string $guard, int $id): void
{
    $authGuard = Mockery::mock(Guard::class);
    $authGuard->shouldReceive('id')->andReturn($id);

    $panel = Mockery::mock(Panel::class);
    $panel->shouldReceive('auth')->andReturn($authGuard);
    $panel->shouldReceive('getAuthGuard')->andReturn($guard);

    Filament::shouldReceive('getCurrentPanel')->andReturn($panel);
    Filament::shouldReceive('getTenant')->andReturnNull();
}

function autosavePlugin(): AutosavePlugin
{
    return AutosavePlugin::make();
}

/**
 * A page behaviour like as an edit page that logs every autosave write.
 */
function makeEditPage(array $formState = [], array $dbState = []): FakeEditPage
{
    return new FakeEditPage($formState, $dbState);
}

/**
 * A page that behaves like a create page and keeps a cached draft.
 */
function makeCreatePage(array $formState = []): FakeCreatePage
{
    return new FakeCreatePage($formState);
}

/** Records calls to Filament transaction helpers. */
function makeTransactionalEditPage(bool $failWrite = false): object
{
    return new class($failWrite)
    {
        use HasAutosave;

        public FakeFormState $form;

        public ?array $data = [];

        /** @var array<int, array<string, mixed>> */
        public array $dispatched = [];

        /** @var array<int, string> */
        public array $txLog = [];

        /** @var array<int, array<string, mixed>> */
        public array $updates = [];

        public bool $failWrite;

        public function __construct(bool $failWrite)
        {
            $this->failWrite = $failWrite;
            $this->form = new FakeFormState;
        }

        public function dispatch(string $event, ...$params): void
        {
            $this->dispatched[] = ['event' => $event, 'params' => $params];
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

        public function getRecord(): object
        {
            return new FakeRecord(['title' => 'old']);
        }

        /** @param  array<string, mixed>  $data */
        public function handleRecordUpdate(object $record, array $data): object
        {
            if ($this->failWrite) {
                throw new RuntimeException('write failed');
            }

            $this->updates[] = $data;

            return $record;
        }
    };
}

function lastStatus(object $page): ?string
{
    $last = end($page->dispatched);

    return $last['params']['status'] ?? null;
}
