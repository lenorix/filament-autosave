<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload\EditUploadPost;
use RuntimeException;

/**
 * The after-write media snapshot fails (a dropped connection) right after a
 * file was written: the cycle fails and the ledger is the only thing that
 * still knows about the file.
 */
class BrokenMediaSnapshotUploadPost extends EditUploadPost
{
    protected function captureAutosaveExternalMediaAfter(): void
    {
        if ($this->autosaveUploadLedgerTokens !== []) {
            throw new RuntimeException('media table unreachable');
        }

        parent::captureAutosaveExternalMediaAfter();
    }
}
