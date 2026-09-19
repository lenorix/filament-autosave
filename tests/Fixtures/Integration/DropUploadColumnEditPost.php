<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

class DropUploadColumnEditPost extends EditUploadPost
{
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['settings']);

        return $data;
    }
}
