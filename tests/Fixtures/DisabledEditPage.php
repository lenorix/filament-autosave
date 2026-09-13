<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures;

class DisabledEditPage extends FakeEditPage
{
    protected function shouldAutosave(): bool
    {
        return false;
    }
}
