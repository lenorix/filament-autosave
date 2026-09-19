<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\EditPost;

class AfterChangedEditPost extends EditPost
{
    protected function afterAutosave(object $record): void
    {
        if ($this->data['title'] === 'First') {
            $this->data['title'] = 'Second';
        }
    }
}
