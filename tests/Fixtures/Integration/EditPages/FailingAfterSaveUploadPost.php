<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload\EditUploadPost;
use RuntimeException;

class FailingAfterSaveUploadPost extends EditUploadPost
{
    protected function afterSave(): void
    {
        throw new RuntimeException('after save failed');
    }
}
