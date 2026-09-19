<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\EditPost;

class ServerChangedEditPost extends EditPost
{
    public function changeSlugOnServer(): void
    {
        $this->data['slug'] = 'server-change';
    }
}
