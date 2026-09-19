<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\EditPost;
use RuntimeException;

class EventsFailingAfterSaveEditPost extends EditPost
{
    protected function afterSave(): void
    {
        throw new RuntimeException('after save failed');
    }
}
