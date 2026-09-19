<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures;

use Lenorix\FilamentAutosave\HasAutosaveUploads;

/** Exposes protected upload helpers without wiring a Livewire component. */
class AutosaveUploadMergeProbe
{
    use HasAutosaveUploads;

    public function __construct(private AutosaveUploadMergeColumnRecord $record) {}

    public function getRecord(): AutosaveUploadMergeColumnRecord
    {
        return $this->record;
    }
}
