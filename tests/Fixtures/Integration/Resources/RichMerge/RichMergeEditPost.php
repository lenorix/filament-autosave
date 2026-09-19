<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\RichMerge;

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\RichUpload\RichUploadEditPost;

/** JSON RichEditor column with a Spatie attachment provider, merged structurally. */
class RichMergeEditPost extends RichUploadEditPost
{
    /** @var list<int> Milliseconds each retry would have waited, recorded instead of slept. */
    public static array $waits = [];

    /** @return array<int, string> */
    protected function autosaveMergeFields(): ?array
    {
        return ['body'];
    }

    protected function autosaveMergeBackoff(int $attempt): void
    {
        static::$waits[] = $this->autosaveMergeBackoffMilliseconds($attempt);
    }
}
