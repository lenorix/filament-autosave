<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\EditPost;

class ProbingAfterValidateEditPost extends EditPost
{
    public int $afterValidateCalls = 0;

    protected function afterValidate(): void
    {
        $this->afterValidateCalls++;
    }
}