<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Filament\Support\Exceptions\Halt;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload\EditUploadPost;

/**
 * Filament's "stop quietly" after the write, with its default of keeping the
 * transaction: the columns are committed.
 */
class HaltingAfterSaveUploadPost extends EditUploadPost
{
    protected function afterSave(): void
    {
        throw new Halt;
    }
}
