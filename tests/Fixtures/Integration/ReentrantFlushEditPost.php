<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

class ReentrantFlushEditPost extends EditPost
{
    protected function afterAutosave(object $record): void
    {
        $this->flushAutosave();
    }
}
