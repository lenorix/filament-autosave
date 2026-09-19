<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Illuminate\Support\Facades\Cache;

class LedgerSpyEditUploadPost extends EditUploadPost
{
    public ?array $ledgerDuringSave = null;

    protected function afterSave(): void
    {
        $this->ledgerDuringSave = Cache::get('filament-autosave:upload-ledger');
    }
}
