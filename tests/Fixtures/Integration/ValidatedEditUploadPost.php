<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

class ValidatedEditUploadPost extends EditUploadPost
{
    protected function getAutosaveValidationRules(): array
    {
        return ['gallery' => ['array', 'max:0']];
    }
}
