<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures;

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
