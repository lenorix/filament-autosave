<?php

namespace Lenorix\FilamentAutosave;

use Lenorix\FilamentAutosave\Contracts\AutosaveExternalUndoAdapter;

final class AutosaveExternalUndoManager
{
    /** @return array<int, AutosaveExternalUndoAdapter> */
    public function adapters(): array
    {
        $configured = config('filament-autosave.external_undo_adapters', []);

        if (! is_array($configured)) {
            return [];
        }

        $adapters = [];

        foreach ($configured as $adapter) {
            try {
                $instance = is_string($adapter) ? app($adapter) : $adapter;
            } catch (\Throwable) {
                continue;
            }

            if ($instance instanceof AutosaveExternalUndoAdapter) {
                $adapters[] = $instance;
            }
        }

        return $adapters;
    }

    public function adapterFor(object $field): ?AutosaveExternalUndoAdapter
    {
        foreach ($this->adapters() as $adapter) {
            try {
                if ($adapter->supports($field)) {
                    return $adapter;
                }
            } catch (\Throwable) {
                // A provider that cannot inspect a field cannot make Undo safe.
            }
        }

        return null;
    }

    /**
     * @param  array<string, object>  $fields
     * @return array<string, array{adapter:class-string, state:array<string, mixed>}>
     */
    public function snapshot(array $fields): array
    {
        $snapshots = [];

        foreach ($fields as $path => $field) {
            $adapter = $this->adapterFor($field);

            if ($adapter === null) {
                continue;
            }

            try {
                $snapshots[$path] = [
                    'adapter' => $adapter::class,
                    'state' => $adapter->snapshot($field),
                ];
            } catch (\Throwable) {
                // A failed snapshot must never make an unsafe Undo available.
            }
        }

        return $snapshots;
    }

    /** @param array<string, object> $fields */
    public function hasUnsupported(array $fields): bool
    {
        foreach ($fields as $field) {
            if ($this->adapterFor($field) === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array{adapter:class-string, state:array<string, mixed>}>  $snapshots
     * @param  array<string, object>  $fields
     */
    public function matches(array $snapshots, array $fields): bool
    {
        // The captured field set is part of the optimistic concurrency check.
        // Restoring a subset would leave a newly-added or removed external
        // field with an unverified state.
        if (array_diff_key($snapshots, $fields) !== [] || array_diff_key($fields, $snapshots) !== []) {
            return false;
        }

        foreach ($snapshots as $path => $entry) {
            $field = $fields[$path] ?? null;
            $adapter = $field ? $this->adapterFor($field) : null;

            if ($adapter === null) {
                return false;
            }

            if ($entry['adapter'] !== $adapter::class) {
                return false;
            }

            try {
                if (! $adapter->matches($field, $entry['state'])) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, array{adapter:class-string, state:array<string, mixed>}>  $snapshots
     * @param  array<string, object>  $fields
     */
    public function restore(array $snapshots, array $fields): void
    {
        foreach ($snapshots as $path => $entry) {
            $field = $fields[$path] ?? null;
            $adapter = $field ? $this->adapterFor($field) : null;

            if ($adapter === null) {
                throw new \LogicException("No reversible autosave adapter is registered for [{$path}].");
            }

            $adapter->restore($field, $entry['state']);
        }
    }
}
