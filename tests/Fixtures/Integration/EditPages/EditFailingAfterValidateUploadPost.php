<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload\EditUploadPost;
use RuntimeException;

class EditFailingAfterValidateUploadPost extends EditUploadPost
{
    protected function afterValidate(): void
    {
        throw new RuntimeException('validation hook failed');
    }
}
