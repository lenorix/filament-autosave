<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

class AfterChangedEditPost extends EditPost
{
    protected function afterAutosave(object $record): void
    {
        if ($this->data['title'] === 'First') {
            $this->data['title'] = 'Second';
        }
    }
}
