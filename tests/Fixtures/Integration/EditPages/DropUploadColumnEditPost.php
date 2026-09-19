<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload\EditUploadPost;

class DropUploadColumnEditPost extends EditUploadPost
{
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['settings']);

        return $data;
    }
}
