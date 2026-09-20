<?php

namespace Lenorix\FilamentAutosave\Events;

/**
 * An autosave operation threw. The write was rolled back before this fires.
 *
 * `$context` is `save`, `sync`, `undo`, or `restore`.
 */
final class AutosaveFailed
{
    public function __construct(
        public readonly object $page,
        public readonly \Throwable $exception,
        public readonly string $context,
    ) {}
}
