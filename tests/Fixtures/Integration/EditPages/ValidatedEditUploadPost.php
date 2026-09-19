<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload\EditUploadPost;

class ValidatedEditUploadPost extends EditUploadPost
{
    protected function getAutosaveValidationRules(): array
    {
        return ['gallery' => ['array', 'max:0']];
    }
}
