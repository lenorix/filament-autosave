<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Support\Exceptions\Halt;

class HaltedSaveEditPost extends EditPost
{
    protected function beforeSave(): void
    {
        throw new Halt;
    }
}
