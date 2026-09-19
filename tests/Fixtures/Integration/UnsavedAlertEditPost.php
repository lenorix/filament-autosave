<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

class UnsavedAlertEditPost extends RelationshipEditPost
{
    protected function hasUnsavedDataChangesAlert(): bool
    {
        return true;
    }
}
