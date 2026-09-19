<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Relationship\RelationshipEditPost;

class UnsavedAlertEditPost extends RelationshipEditPost
{
    protected function hasUnsavedDataChangesAlert(): bool
    {
        return true;
    }
}
