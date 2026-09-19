<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\EditPost;

class ValidatedEditPost extends EditPost
{
    protected function getAutosaveValidationRules(): array
    {
        return ['title' => ['max:3']];
    }
}
