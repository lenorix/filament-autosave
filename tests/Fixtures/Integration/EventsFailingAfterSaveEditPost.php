<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use RuntimeException;

class EventsFailingAfterSaveEditPost extends EditPost
{
    protected function afterSave(): void
    {
        throw new RuntimeException('after save failed');
    }
}
