<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

class EditFailingStoragePost extends EditUploadPost
{
    protected static string $resource = FailingStoragePostResource::class;
}
