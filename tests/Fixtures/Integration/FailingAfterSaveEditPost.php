<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use RuntimeException;

class FailingAfterSaveEditPost extends EditPost
{
    protected function afterSave(): void
    {
        throw new RuntimeException('after save failed');
    }
}
