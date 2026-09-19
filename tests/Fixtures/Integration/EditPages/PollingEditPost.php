<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\EditPost;

class PollingEditPost extends EditPost
{
    protected function autosavePollInterval(): ?int
    {
        return 1234;
    }
}
