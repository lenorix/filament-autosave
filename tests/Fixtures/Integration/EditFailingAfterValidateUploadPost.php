<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use RuntimeException;

class EditFailingAfterValidateUploadPost extends EditUploadPost
{
    protected function afterValidate(): void
    {
        throw new RuntimeException('validation hook failed');
    }
}
