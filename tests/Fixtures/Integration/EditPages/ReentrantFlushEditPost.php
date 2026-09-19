<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\EditPost;

class ReentrantFlushEditPost extends EditPost
{
    protected function afterAutosave(object $record): void
    {
        $this->flushAutosave();
    }
}
