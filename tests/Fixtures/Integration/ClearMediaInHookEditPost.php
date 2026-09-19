<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

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
