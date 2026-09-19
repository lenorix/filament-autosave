<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use DomainException;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\EditPost;

class GuardedFlushEditPost extends EditPost
{
    protected function beforeAutosave(array $data): array
    {
        if (($data['title'] ?? null) === 'forbidden') {
            throw new DomainException('title is reserved');
        }

        return $data;
    }
}
