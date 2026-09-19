<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

/**
 * Mounts with the sha256 field hashes a generic form carried before the
 * Undo engine unified hashing on xxh128, as a tab opened before that deploy
 * would.
 */
class LegacyHashRecordForm extends AutosaveColumnsRecordForm
{
    public function mountHasAutosaveForForm(): void
    {
        parent::mountHasAutosaveForForm();

        $this->autosaveFieldHashes = array_map(
            fn (string $hash): string => hash('sha256', $hash),
            $this->autosaveFieldHashes,
        );
    }
}
