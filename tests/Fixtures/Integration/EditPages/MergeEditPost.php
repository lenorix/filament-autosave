<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\EditPost;

class MergeEditPost extends EditPost
{
    /** @var list<int> Milliseconds each retry would have waited, recorded instead of slept. */
    public static array $waits = [];

    /** @return array<int, string> */
    protected function autosaveMergeFields(): ?array
    {
        return ['title'];
    }

    protected function autosaveMergeBackoff(int $attempt): void
    {
        static::$waits[] = $this->autosaveMergeBackoffMilliseconds($attempt);
    }
}
