<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use RuntimeException;

class FailingAfterSaveUploadPost extends EditUploadPost
{
    protected function afterSave(): void
    {
        throw new RuntimeException('after save failed');
    }
}
