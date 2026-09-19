<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\EditPost;
use RuntimeException;

/**
 * Simulates a refill that fails once — a fill hook throwing, a dead cache —
 * so the poll's bookkeeping around the failure can be pinned.
 */
class FailingRefillEditPost extends EditPost
{
    public bool $autosaveFailNextRefill = false;

    protected function refillAutosaveFieldsFromRecord(object $record, array $paths): void
    {
        if ($this->autosaveFailNextRefill) {
            $this->autosaveFailNextRefill = false;

            throw new RuntimeException('Refill failed');
        }

        parent::refillAutosaveFieldsFromRecord($record, $paths);
    }
}
