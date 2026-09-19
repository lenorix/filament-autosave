<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms;

class AutosaveMergeRecordForm extends AutosaveColumnsRecordForm
{
    /** @var list<int> Milliseconds each retry would have waited, recorded instead of slept. */
    public static array $waits = [];

    /** @return array<int, string> */
    protected function autosaveMergeFields(): ?array
    {
        return ['title'];
    }

    protected function getAutosaveFormContext(): string
    {
        return 'merge:'.$this->record->getKey();
    }

    protected function autosaveMergeBackoff(int $attempt): void
    {
        static::$waits[] = $this->autosaveMergeBackoffMilliseconds($attempt);
    }
}
