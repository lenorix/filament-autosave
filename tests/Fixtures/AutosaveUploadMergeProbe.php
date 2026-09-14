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

/** Minimal persisted-record double exposing column reads. */
class AutosaveUploadMergeColumnRecord
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(private array $attributes) {}

    public function getAttribute(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }
}
