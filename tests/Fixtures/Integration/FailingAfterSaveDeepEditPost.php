<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use RuntimeException;

class FailingAfterSaveDeepEditPost extends DeepRelationshipEditPost
{
    protected function afterSave(): void
    {
        throw new RuntimeException('after save failed');
    }
}
