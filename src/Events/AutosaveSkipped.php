<?php

namespace Lenorix\FilamentAutosave\Events;

/**
 * An autosave cycle ran to completion but wrote nothing.
 *
 * `$reason` is `validation` when every changed field was dropped by
 * validation or the package's safety checks, or `unchanged` when nothing
 * differed from the last acknowledged state.
 */
final class AutosaveSkipped
{
    /**
     * @param  array<int, string>  $pending
     * @param  array<string, array<int, string>>  $errors
     */
    public function __construct(
        public readonly object $page,
        public readonly string $reason,
        public readonly array $pending,
        public readonly array $errors,
    ) {}
}
