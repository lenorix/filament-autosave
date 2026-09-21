<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Cycle;

use Filament\Resources\Pages\EditRecord;
use Lenorix\FilamentAutosave\HasAutosave;

class ThroughRelationEditPost extends EditRecord
{
    use HasAutosave;

    protected static string $resource = ThroughRelationPostResource::class;

    /** @return array{fingerprints: array<string, string>, unfingerprinted: list<string>} */
    public function probeAutosaveRelationFingerprints(): array
    {
        return $this->autosaveRelationFingerprints($this->getRecord());
    }
}
