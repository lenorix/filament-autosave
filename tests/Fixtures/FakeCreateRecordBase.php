<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/** Create double that records success before starting another record. */
class FakeCreateRecordBase
{
    public ?Model $record = null;

    public bool $shouldHalt = false;

    public function create(bool $another = false): void
    {
        if ($this->shouldHalt) {
            return;
        }

        $this->record = $this->handleRecordCreation(['title' => 'created']);

        $this->rememberData();

        if ($another) {
            $this->record = null;
        }
    }

    protected function rememberData(): void {}

    /** @param  array<string, mixed>  $data */
    protected function handleRecordCreation(array $data): Model
    {
        $record = new class extends Model
        {
            protected $guarded = [];
        };

        $record->exists = true;
        $record->setAttribute($record->getKeyName(), 1);

        return $record;
    }

    public function getRecord(): ?Model
    {
        return $this->record;
    }
}
