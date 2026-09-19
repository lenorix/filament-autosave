<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

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
