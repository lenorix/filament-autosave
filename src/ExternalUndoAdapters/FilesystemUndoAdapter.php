<?php

namespace Lenorix\FilamentAutosave\ExternalUndoAdapters;

use Filament\Forms\Components\BaseFileUpload;
use Lenorix\FilamentAutosave\Contracts\AutosaveExternalUndoAdapter;

/**
 * Reversible Undo for a plain `FileUpload` field backed by a Laravel
 * filesystem disk. Not automatically wired in: register an instance per
 * disk in `external_undo_adapters` to opt a form into file Undo.
 *
 * Does not handle `SpatieMediaLibraryFileUpload` (a relationship, not a raw
 * disk path — the ledger already journals and prunes its files) or
 * `RichEditor` attachments (their own provider owns reversibility).
 *
 * A field's raw state can hold values this adapter cannot resolve to a
 * stored path yet — a Livewire `TemporaryUploadedFile` mid-cycle, before
 * the write moves it to permanent storage. Only fully resolved string
 * paths are ever treated as "the state"; anything else is read as "no
 * file here yet", which is always the safe reading for Undo.
 */
final class FilesystemUndoAdapter implements AutosaveExternalUndoAdapter
{
    public function __construct(private readonly string $disk) {}

    public function supports(object $field): bool
    {
        if (! $field instanceof BaseFileUpload || $this->isUnsupportedFileUpload($field)) {
            return false;
        }

        return method_exists($field, 'getDiskName') && $field->getDiskName() === $this->disk;
    }

    /** @return array{paths: list<string>, contents: array<string, string|null>} */
    public function snapshot(object $field): array
    {
        $disk = $this->diskFor($field);
        $paths = $this->resolvedPaths($this->rawState($field));
        $contents = [];

        foreach ($paths as $path) {
            $contents[$path] = $disk->exists($path) ? $disk->get($path) : null;
        }

        return ['paths' => $paths, 'contents' => $contents];
    }

    /** @param array<string, mixed> $snapshot */
    public function matches(object $field, array $snapshot): bool
    {
        $disk = $this->diskFor($field);
        $current = $this->resolvedPaths($this->rawState($field));
        $expected = $this->expectedPaths($snapshot);

        if ($current !== $expected) {
            return false;
        }

        foreach ($current as $path) {
            if (! $disk->exists($path)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $snapshot */
    public function restore(object $field, array $snapshot): void
    {
        $disk = $this->diskFor($field);
        $expectedPaths = $this->expectedPaths($snapshot);
        $expectedContents = is_array($snapshot['contents'] ?? null) ? $snapshot['contents'] : [];
        $currentPaths = $this->resolvedPaths($this->rawState($field));

        // Delete a path the snapshot did not know about: it was stored by
        // the cycle being undone and never existed before it.
        foreach (array_diff($currentPaths, $expectedPaths) as $path) {
            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        }

        // Put back a path the snapshot did know about, byte for byte. A
        // missing backup (the file never existed, or content could not be
        // read at snapshot time) leaves nothing to restore for that path.
        foreach ($expectedPaths as $path) {
            $contents = $expectedContents[$path] ?? null;

            if ($contents === null) {
                continue;
            }

            if (! $disk->put($path, $contents)) {
                throw new \RuntimeException("Could not restore [{$path}] on disk [{$this->disk}].");
            }
        }
    }

    private function isUnsupportedFileUpload(BaseFileUpload $field): bool
    {
        $spatieClass = 'Filament\\Forms\\Components\\SpatieMediaLibraryFileUpload';

        return class_exists($spatieClass) && $field instanceof $spatieClass;
    }

    private function diskFor(object $field): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return method_exists($field, 'getDisk')
            ? $field->getDisk()
            : \Illuminate\Support\Facades\Storage::disk($this->disk);
    }

    private function rawState(object $field): mixed
    {
        return method_exists($field, 'getRawState') ? $field->getRawState() : null;
    }

    /** @return list<string> */
    private function resolvedPaths(mixed $state): array
    {
        if (is_string($state)) {
            return [$state];
        }

        if (! is_array($state)) {
            return [];
        }

        $paths = [];

        foreach ($state as $value) {
            $paths = [...$paths, ...$this->resolvedPaths($value)];
        }

        return $paths;
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return list<string>
     */
    private function expectedPaths(array $snapshot): array
    {
        $paths = $snapshot['paths'] ?? [];

        return is_array($paths) ? array_values(array_filter($paths, 'is_string')) : [];
    }
}
