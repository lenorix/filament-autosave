<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Browser;

use Illuminate\Database\Eloquent\Model;

/**
 * Every compare-and-swap loses, as if another editor always got there
 * first: the browser ends each save with a contended body.
 */
class BrowserContendedEditPost extends BrowserMergeEditPost
{
    protected static string $resource = BrowserContendedPostResource::class;

    protected function autosaveCompareAndSwap(Model $record, string $column, mixed $expected, string $value): bool
    {
        return false;
    }

    protected function autosaveMergeBackoff(int $attempt): void
    {
        // No waiting: the browser test only cares about the exhausted result.
    }
}
