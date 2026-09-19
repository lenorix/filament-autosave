<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

class PollingEditPost extends EditPost
{
    protected function autosavePollInterval(): ?int
    {
        return 1234;
    }
}
