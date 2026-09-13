<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures;

use Closure;

/** Small persisted-model double for autosave tests. */
class FakeRecord
{
    /**
     * @param  array<string, mixed>  $fields
     */
    public function __construct(public array $fields = [], private ?Closure $onRefresh = null) {}

    public function refresh(): static
    {
        if ($this->onRefresh !== null) {
            ($this->onRefresh)();
        }

        return $this;
    }

    /** @param  array<int, string>  $keys
     * @return array<string, mixed>
     */
    public function only(array $keys): array
    {
        return array_intersect_key($this->fields, array_flip($keys));
    }

    /** @return array<string, mixed> */
    public function getAttributes(): array
    {
        return $this->fields;
    }

    public function getKey(): int
    {
        return 1;
    }
}
