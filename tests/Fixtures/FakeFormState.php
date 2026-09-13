<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures;

/** Minimal Filament form double for raw state and filling. */
class FakeFormState
{
    /** @param array<string, mixed> $state */
    public function __construct(public array $state = []) {}

    /** @return array<string, mixed> */
    public function getRawState(): array
    {
        return $this->state;
    }

    /** @param array<string, mixed> $data */
    public function fill(array $data): void
    {
        $this->state = $data;
    }

    /** @param array<string, mixed> $state */
    public function setState(array $state): void
    {
        $this->state = $state;
    }
}
