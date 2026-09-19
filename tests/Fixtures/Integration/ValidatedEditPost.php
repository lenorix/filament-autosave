<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

class ValidatedEditPost extends EditPost
{
    protected function getAutosaveValidationRules(): array
    {
        return ['title' => ['max:3']];
    }
}
