<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload\EditUploadPost;

class HookedEditUploadPost extends EditUploadPost
{
    public int $beforeCalls = 0;

    protected function beforeAutosave(array $data): array
    {
        $this->beforeCalls++;
        unset($data['gallery']);

        return $data;
    }
}
