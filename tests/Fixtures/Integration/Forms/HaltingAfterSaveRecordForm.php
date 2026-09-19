<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms;

use Filament\Support\Exceptions\Halt;

/** Filament's "stop quietly" after the write, keeping the transaction. */
class HaltingAfterSaveRecordForm extends AutosaveUploadRecordForm
{
    protected function afterSave(): void
    {
        throw new Halt;
    }
}
