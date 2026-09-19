<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Deep\DeepRelationshipEditPost;
use RuntimeException;

class FailingAfterSaveDeepEditPost extends DeepRelationshipEditPost
{
    protected function afterSave(): void
    {
        throw new RuntimeException('after save failed');
    }
}
