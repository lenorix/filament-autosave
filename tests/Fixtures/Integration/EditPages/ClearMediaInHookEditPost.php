<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload\EditUploadPost;

class ClearMediaInHookEditPost extends EditUploadPost
{
    protected function beforeAutosave(array $data): array
    {
        if (array_key_exists('gallery', $data)) {
            $data['gallery'] = [];
        }

        return $data;
    }
}
