<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Illuminate\Support\Facades\Cache;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload\EditUploadPost;

class LedgerSpyEditUploadPost extends EditUploadPost
{
    public ?array $ledgerDuringSave = null;

    protected function afterSave(): void
    {
        $this->ledgerDuringSave = Cache::get('filament-autosave:upload-ledger');
    }
}
