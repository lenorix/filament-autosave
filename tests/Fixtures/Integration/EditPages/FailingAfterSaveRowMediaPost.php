<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload\EditRowMediaPost;
use RuntimeException;

class FailingAfterSaveRowMediaPost extends EditRowMediaPost
{
    protected function afterSave(): void
    {
        throw new RuntimeException('after save failed');
    }
}
