<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Filament\Support\Exceptions\Halt;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\EditPost;

class HaltedSaveEditPost extends EditPost
{
    protected function beforeSave(): void
    {
        throw new Halt;
    }
}
