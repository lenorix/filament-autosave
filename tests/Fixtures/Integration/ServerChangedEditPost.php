<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

class ServerChangedEditPost extends EditPost
{
    public function changeSlugOnServer(): void
    {
        $this->data['slug'] = 'server-change';
    }
}
