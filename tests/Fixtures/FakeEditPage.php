<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures;

use Lenorix\FilamentAutosave\HasAutosave;

/** Edit-page double that records autosave writes. */
class FakeEditPage
{
    use HasAutosave;

    public FakeFormState $form;

    public ?array $data = [];

    /** @var array<int, array<string, mixed>> */
    public array $dispatched = [];

    /** @var array<int, array<string, mixed>> */
    public array $updates = [];

    public int $refreshCount = 0;

    /** @var array<string> */
    public array $exceptFields = [];

    /** @var array<string, mixed> */
    private array $dbState;

    /**
     * @param  array<string, mixed>  $formState
     * @param  array<string, mixed>  $dbState
     */
    public function __construct(array $formState = [], array $dbState = [])
    {
        $this->dbState = $dbState;

        $this->form = new FakeFormState($formState);
    }

    /** @return array<string> */
    protected function autosaveExcept(): array
    {
        return $this->exceptFields;
    }

    public function dispatch(string $event, ...$params): void
    {
        $this->dispatched[] = ['event' => $event, 'params' => $params];
    }

    public function authorizeAccess(): void {}

    public function getRecord(): object
    {
        return new FakeRecord($this->dbState, function () {
            $this->refreshCount++;
        });
    }

    /** @param  array<string, mixed>  $data */
    public function handleRecordUpdate(object $record, array $data): object
    {
        $this->updates[] = $data;
        $this->dbState = array_replace($this->dbState, $data);

        return $record;
    }

    /** @param array<string, mixed> $data */
    public function simulateConcurrentUpdate(array $data): void
    {
        $this->dbState = array_replace($this->dbState, $data);
    }

    /** @return array<string, mixed> */
    public function currentDbState(): array
    {
        return $this->dbState;
    }
}
