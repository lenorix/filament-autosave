<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms;

use Illuminate\Database\Eloquent\Relations\Relation;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\PollNote;

class VersionedPollRelationsRecordForm extends PollRelationsRecordForm
{
    protected function getAutosavePollFingerprint(string $path, Relation $relation): ?string
    {
        if ($path !== 'notes') {
            return null;
        }

        return PollNote::query()
            ->orderBy('id')
            ->pluck('body')
            ->implode('|');
    }
}
