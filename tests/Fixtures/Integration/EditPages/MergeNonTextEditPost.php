<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\EditPost;

/** Lists a non-text path (`settings` is a Group with a Select inside). */
class MergeNonTextEditPost extends EditPost
{
    /** @return array<int, string> */
    protected function autosaveMergeFields(): ?array
    {
        return ['title', 'settings'];
    }
}
