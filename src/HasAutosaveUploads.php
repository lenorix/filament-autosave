<?php

namespace Lenorix\FilamentAutosave;

use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Adds Edit-page autosave support for Filament file-upload components.
 *
 * This is an internal companion trait and should not be added directly to a
 * page. Use {@see HasAutosave}; it composes this trait with the common
 * autosave lifecycle and supplies the methods used here, such as
 * `getAutosaveFields()` and `prepareAutosavePayload()`.
 *
 * Supported components are column-backed `FileUpload` fields and top-level
 * `SpatieMediaLibraryFileUpload` fields. The trait tracks upload state by hash,
 * validates changed uploads, and leaves unchanged upload fields alone. Uploads
 * in nested Spatie fields and nested relationship components remain explicit-save
 * concerns. Upload operations also do not participate in column Undo because
 * filesystem changes cannot be rolled back by a database transaction.
 *
 * @internal
 */
trait HasAutosaveUploads
{
    /**
     * Hash of each upload field's ordered state, keyed by its full form path.
     *
     * This is public only so Livewire can carry it between requests. It contains
     * hashes, never file contents or original upload objects, and is locked
     * against browser updates.
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $autosaveUploadHashes = [];

    /**
     * Media identifiers and paths present before the current autosave-owned
     * form state was dehydrated. The metadata is safe to carry through
     * Livewire; file contents are kept only for the lifetime of this request.
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    #[Locked]
    public array $autosaveExternalMediaBaseline = [];

    #[Locked]
    public bool $autosaveExternalMediaBaselineCaptured = false;

    /** @var array<string, array<int, mixed>> */
    #[Locked]
    public array $autosaveRichEditorAttachmentBaseline = [];

    /**
     * Upload fields that passed the preliminary checks for the current request.
     *
     * @var array<string, BaseFileUpload>
     */
    protected array $autosavePendingUploads = [];

    /**
     * Top-level column names whose upload state must be left untouched.
     *
     * @var array<string, true>
     */
    protected array $autosaveBlockedUploadColumns = [];

    /** @var array<string, array<string>> Newly stored paths to clean on failure. */
    protected array $autosaveStoredUploadPaths = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    protected array $autosaveExternalMediaAfter = [];

    /** @var array<string, string> */
    protected array $autosaveExternalMediaBackups = [];

    /**
     * Find declared FileUpload fields, including hidden fields and fields nested
     * under a repeater or state path.
     *
     * @return array<string, BaseFileUpload> Full state path => field instance.
     */
    protected function autosaveUploadFields(): array
    {
        $form = $this->resolveAutosaveForm();

        if (! $form || ! method_exists($form, 'getFlatFields')) {
            return [];
        }

        return array_filter($form->getFlatFields(withHidden: true),
            fn ($field): bool => $field instanceof BaseFileUpload);
    }

    /** Capture the external media baseline before RichEditor cleanup runs. */
    protected function captureAutosaveExternalMediaBaseline(): void
    {
        if ($this->autosaveExternalMediaBaselineCaptured) {
            $this->backupAutosaveExternalMedia($this->autosaveExternalMediaBaseline);

            return;
        }

        $this->autosaveExternalMediaBaseline = $this->captureAutosaveExternalMedia();
        $this->autosaveRichEditorAttachmentBaseline = $this->captureAutosaveRichEditorAttachments();
        $this->autosaveExternalMediaBaselineCaptured = true;
        $this->backupAutosaveExternalMedia($this->autosaveExternalMediaBaseline);
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    protected function captureAutosaveExternalMedia(): array
    {
        $snapshot = [];
        $fields = [];

        foreach ($this->autosaveUploadFields() as $field) {
            if ($field instanceof SpatieMediaLibraryFileUpload) {
                $fields[] = $field;
            }
        }

        if (method_exists($this, 'autosaveRelationshipFields')) {
            foreach ($this->autosaveRelationshipFields() as $fieldSet) {
                foreach ($fieldSet as $field) {
                    if ($field instanceof RichEditor) {
                        $fields[] = $field;
                    }
                }
            }
        }

        foreach ($fields as $field) {
            $record = method_exists($field, 'getRecord') ? $field->getRecord() : null;

            if (! is_object($record) || ! method_exists($record, 'getMedia')) {
                continue;
            }

            $collection = $this->autosaveExternalMediaCollection($field);

            if ($collection === null) {
                continue;
            }

            $key = $record::class.':'.($record->getKey() ?? 'new').':'.$collection;
            $snapshot[$key] = [];

            foreach ($record->getMedia($collection) as $media) {
                $snapshot[$key][] = $this->autosaveExternalMediaMetadata($media);
            }
        }

        return $snapshot;
    }

    /** @return array<string, array<int, mixed>> */
    protected function captureAutosaveRichEditorAttachments(): array
    {
        $attachments = [];

        if (! method_exists($this, 'autosaveRelationshipFields')) {
            return $attachments;
        }

        foreach ($this->autosaveRelationshipFields() as $fieldSet) {
            foreach ($fieldSet as $field) {
                if (! $field instanceof RichEditor || ! method_exists($field, 'resolveFileAttachmentIds')) {
                    continue;
                }

                $path = method_exists($field, 'getStatePath') ? (string) $field->getStatePath() : spl_object_id($field);

                try {
                    $attachments[$path] = $field->resolveFileAttachmentIds();
                } catch (\Throwable) {
                    $attachments[$path] = [];
                }
            }
        }

        return $attachments;
    }

    protected function autosaveExternalMediaCollection(object $field): ?string
    {
        if (method_exists($field, 'getCollection')) {
            return $field->getCollection() ?? 'default';
        }

        if ($field instanceof RichEditor && method_exists($field, 'getFileAttachmentProvider')) {
            $provider = $field->getFileAttachmentProvider();

            if ($provider !== null && method_exists($provider, 'getCollection')) {
                return $provider->getCollection();
            }

            if ($provider !== null && method_exists($provider, 'getMedia')) {
                $media = $provider->getMedia();

                return $media?->first()?->collection_name;
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    protected function autosaveExternalMediaMetadata(object $media): array
    {
        $metadata = [
            'uuid' => method_exists($media, 'getAttribute') ? $media->getAttribute('uuid') : null,
            'disk' => method_exists($media, 'getAttribute') ? $media->getAttribute('disk') : null,
            'path' => method_exists($media, 'getPathRelativeToRoot') ? $media->getPathRelativeToRoot() : null,
            'conversions_disk' => method_exists($media, 'getAttribute') ? $media->getAttribute('conversions_disk') : null,
            'conversion_paths' => [],
        ];

        if (method_exists($media, 'getMediaConversionNames') && method_exists($media, 'getPathRelativeToRoot')) {
            foreach ($media->getMediaConversionNames() as $conversion) {
                $metadata['conversion_paths'][] = [
                    'disk' => $metadata['conversions_disk'] ?: $metadata['disk'],
                    'path' => $media->getPathRelativeToRoot($conversion),
                ];
            }
        }

        return $metadata;
    }

    /** @param array<string, array<int, array<string, mixed>>> $snapshot */
    protected function backupAutosaveExternalMedia(array $snapshot): void
    {
        foreach ($snapshot as $rows) {
            foreach ($rows as $media) {
                $disk = $media['disk'] ?? null;
                $path = $media['path'] ?? null;

                if (! is_string($disk) || ! is_string($path) || isset($this->autosaveExternalMediaBackups[$disk.':'.$path])) {
                    continue;
                }

                try {
                    if (Storage::disk($disk)->exists($path)) {
                        $this->autosaveExternalMediaBackups[$disk.':'.$path] = (string) Storage::disk($disk)->get($path);
                    }
                } catch (\Throwable) {
                    // A remote disk may not support reads; DB rollback still
                    // protects its metadata and new files are still removed.
                }
            }
        }
    }

    /** Record media created/deleted while callbacks and hooks run. */
    protected function captureAutosaveExternalMediaAfter(): void
    {
        $this->autosaveExternalMediaAfter = $this->captureAutosaveExternalMedia();
    }

    /** Remove new media files and put back files removed by a failed cycle. */
    protected function rollbackAutosaveExternalMedia(): void
    {
        $before = collect($this->autosaveExternalMediaBaseline)->flatten(1)->keyBy('uuid');
        $after = collect($this->autosaveExternalMediaAfter)->flatten(1)->keyBy('uuid');

        foreach ($after->except($before->keys()) as $media) {
            $this->deleteAutosaveExternalMediaFiles($media);
        }

        foreach ($this->autosaveExternalMediaBackups as $key => $contents) {
            [$disk, $path] = explode(':', $key, 2);

            try {
                Storage::disk($disk)->put($path, $contents);
            } catch (\Throwable) {
                // Preserve the original failure and leave provider-specific
                // recovery to the application's storage implementation.
            }
        }

        $this->cleanupAutosaveRichEditorAttachments();

        $this->autosaveExternalMediaAfter = [];
        $this->autosaveExternalMediaBackups = [];
    }

    /** Let providers remove newly-created attachments outside the DB. */
    protected function cleanupAutosaveRichEditorAttachments(): void
    {
        if (! method_exists($this, 'autosaveRelationshipFields')) {
            return;
        }

        foreach ($this->autosaveRelationshipFields() as $fieldSet) {
            foreach ($fieldSet as $field) {
                if (! $field instanceof RichEditor || ! method_exists($field, 'getFileAttachmentProvider')) {
                    continue;
                }

                $provider = $field->getFileAttachmentProvider();

                if ($provider === null || ! method_exists($provider, 'cleanUpFileAttachments')) {
                    continue;
                }

                $path = method_exists($field, 'getStatePath') ? (string) $field->getStatePath() : spl_object_id($field);

                try {
                    $provider->cleanUpFileAttachments($this->autosaveRichEditorAttachmentBaseline[$path] ?? []);
                } catch (\Throwable) {
                    // Cleanup must never hide the original autosave failure.
                }
            }
        }
    }

    /** @param array<string, mixed> $media */
    protected function deleteAutosaveExternalMediaFiles(array $media): void
    {
        $paths = [[
            'disk' => $media['disk'] ?? null,
            'path' => $media['path'] ?? null,
        ], ...($media['conversion_paths'] ?? [])];

        foreach ($paths as $entry) {
            if (is_string($entry['disk'] ?? null) && is_string($entry['path'] ?? null)) {
                try {
                    Storage::disk($entry['disk'])->delete($entry['path']);
                } catch (\Throwable) {
                    // Cleanup is best effort and must not replace the save
                    // error that caused the rollback.
                }
            }
        }
    }

    /**
     * Hash upload identifiers and their order without reading file contents.
     *
     * Temporary uploads use Livewire's temporary filename; stored uploads use
     * their path or media identifier. Reordering therefore produces a new hash.
     */
    protected function autosaveUploadHash(BaseFileUpload $field): string
    {
        // Preserve order, but ignore FilePond's ephemeral keys. Never read file bytes.
        $state = array_map(
            fn ($file) => $file instanceof TemporaryUploadedFile
                ? ['temporary' => $file->getFilename()]
                : ['stored' => $file],
            array_values($field->getRawState() ?? []),
        );

        return $this->autosaveStore()->snapshotHash(['files' => $state]);
    }

    /** Capture the current upload hashes as the acknowledged baseline. */
    protected function resetAutosaveUploadHashes(): void
    {
        $this->autosaveUploadHashes = [];

        foreach ($this->autosaveUploadFields() as $path => $field) {
            $this->autosaveUploadHashes[$path] = $this->autosaveUploadHash($field);
        }
    }

    /**
     * Build the upload part of the current persistence plan.
     *
     * Changed fields are validated first. Standard FileUpload fields are stored
     * when they pass the plan; Spatie fields are deferred to their relationship
     * callback. Invalid, excluded, disabled, hidden, nested-relationship, or
     * incomplete-container fields are recorded in
     * `$autosaveBlockedUploadColumns` and remain untouched.
     */
    protected function prepareAutosavePersistence(): void
    {
        $this->autosaveFieldsCache = null;
        $this->autosavePendingUploads = [];
        $this->autosaveBlockedUploadColumns = [];
        $this->autosaveStoredUploadPaths = [];

        foreach ($this->autosaveUploadFields() as $path => $field) {
            $top = AutosaveFieldTree::topLevelKey($path);
            $media = $field instanceof SpatieMediaLibraryFileUpload;

            if ($this->autosavePathExcluded($path)) {
                continue;
            }

            if (($this->autosaveUploadHashes[$path] ?? null) === $this->autosaveUploadHash($field)) {
                continue;
            }

            // Relationships inside repeaters need their own record lifecycle.
            if (($media && str_contains($path, '.')) || $field->isDisabled() || $field->isHidden()
                || ! $field->shouldStoreFiles() || (! $media && ! $field->isDehydrated())
                || (method_exists($field, 'isSaved') && ! $field->isSaved())) {
                $this->autosaveBlockedUploadColumns[$top] = true;

                continue;
            }

            if (! $this->validateAutosaveUpload($field)) {
                $this->autosaveBlockedUploadColumns[$top] = true;

                continue;
            }

            $this->autosavePendingUploads[$path] = $field;
        }

        if ($this->autosavePendingUploads === []) {
            return;
        }

        $safe = $this->normalizeStateArray($this->resolveAutosaveForm()?->getRawState());
        foreach ($this->getAutosaveFields() as $path => $fields) {
            foreach ($fields as $field) {
                if (! $field instanceof SpatieMediaLibraryFileUpload
                    && method_exists($field, 'isDehydrated') && ! $field->isDehydrated()) {
                    foreach ($this->matchAutosavePaths($safe, $path) as $match) {
                        $this->forgetAutosavePath($safe, $match);
                    }
                }
            }
        }
        $safe = $this->dropIncompleteAutosaveContainers($this->enforceFieldOptionRules(
            $this->dropBlankRequiredAutosaveFields($this->dropPasswordFields($safe)),
        ));

        foreach ($this->autosavePendingUploads as $path => $field) {
            $top = AutosaveFieldTree::topLevelKey($path);

            if (! array_key_exists($top, $safe)) {
                $this->autosaveBlockedUploadColumns[$top] = true;
            }

            if (isset($this->autosaveBlockedUploadColumns[$top])) {
                unset($this->autosavePendingUploads[$path]);
            }
        }
    }

    /**
     * Store standard uploads after the complete form validation has passed.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function storeAutosavePendingUploads(array $data): array
    {
        foreach ($this->autosavePendingUploads as $path => $field) {
            if ($field instanceof SpatieMediaLibraryFileUpload) {
                continue;
            }

            $before = $this->autosaveUploadStatePaths($field->getRawState());
            $field->saveUploadedFiles();
            $after = $this->autosaveUploadStatePaths($field->getRawState());
            $newPaths = array_values(array_diff($after, $before));

            if ($newPaths !== []) {
                $this->autosaveStoredUploadPaths[$path] = $newPaths;
            }

            if ($newPaths !== []) {
                $storedState = $field->getRawState();

                if (is_array($storedState)) {
                    $storedState = $field->isMultiple()
                        ? array_values($storedState)
                        : (array_values($storedState)[0] ?? null);
                }

                data_set($data, $path, $storedState);
            }

            if (method_exists($field, 'getFileNamesStatePath')
                && filled($namesPath = $field->getFileNamesStatePath())
                && ($names = $field->getStoredFileNames()) !== null) {
                $relativeNamesPath = $this->autosaveRelativeUploadPath($namesPath);

                if ($relativeNamesPath !== '') {
                    data_set($data, $relativeNamesPath, $names);
                }
            }
        }

        return $data;
    }

    /**
     * Drop uploads removed by a form mutator before the record write begins.
     *
     * A mutator runs after standard uploads have been moved to permanent
     * storage. If it removes that field, retaining the pending entry would
     * acknowledge a path that was never written to the model and leave an
     * orphaned file behind.
     *
     * @param  array<string, mixed>  $data
     */
    protected function filterAutosavePendingUploadsForPayload(array $data): void
    {
        foreach ($this->autosavePendingUploads as $path => $field) {
            $top = AutosaveFieldTree::topLevelKey($path);

            if (! array_key_exists($top, $data)) {
                $this->discardAutosaveStoredUploadPaths([$path]);
                unset($this->autosavePendingUploads[$path]);

                continue;
            }

            $stored = $this->autosaveStoredUploadPaths[$path] ?? [];

            if ($stored !== [] && array_intersect(
                $stored,
                $this->autosaveUploadStatePaths(data_get($data, $path)),
            ) === []) {
                $this->discardAutosaveStoredUploadPaths([$path]);
                unset($this->autosavePendingUploads[$path]);
            }
        }
    }

    /**
     * Consume pending uploads from the persistence payload.
     *
     * Standard uploads stay in the column payload after they have been stored.
     * Media-library uploads are persisted through their relationship callback,
     * so their raw state is refreshed, validated, and removed from the payload.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, BaseFileUpload>
     */
    protected function consumePendingUploads(array &$data): array
    {
        $uploads = [];

        foreach ($this->autosavePendingUploads as $path => $field) {
            if (! data_has($data, $path)) {
                continue;
            }

            $uploads[$path] = $field;

            if (! $field instanceof SpatieMediaLibraryFileUpload) {
                continue;
            }

            $field->rawState(data_get($data, $path));

            if (! $this->validateAutosaveUpload($field)) {
                unset($uploads[$path]);
            }

            unset($data[$path]);
        }

        return $uploads;
    }

    /**
     * Keep uploads that still have a column or media relationship to persist.
     *
     * @param  array<string, BaseFileUpload>  $uploads
     * @param  array<string, mixed>  $data
     * @return array<string, BaseFileUpload>
     */
    protected function filterPersistableAutosaveUploads(array $uploads, array $data): array
    {
        return array_filter(
            $uploads,
            fn (BaseFileUpload $field, string $path): bool => $this->autosaveUploadCanPersist($field, $path, $data),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    protected function autosaveUploadCanPersist(BaseFileUpload $field, string $path, array $data): bool
    {
        return $field instanceof SpatieMediaLibraryFileUpload
            || array_key_exists(AutosaveFieldTree::topLevelKey($path), $data);
    }

    /** Persist media-library uploads through their relationship callback. */
    protected function persistAutosaveUploadRelationships(array $uploads): void
    {
        foreach ($uploads as $field) {
            if ($field instanceof SpatieMediaLibraryFileUpload) {
                $field->saveRelationships();
            }
        }
    }

    /** Acknowledge upload hashes only after their persistence completed. */
    protected function acknowledgeAutosaveUploads(array $uploads, array $data): void
    {
        foreach ($uploads as $path => $field) {
            if ($this->autosaveUploadCanPersist($field, $path, $data)) {
                $this->autosaveUploadHashes[$path] = $this->autosaveUploadHash($field);
            }
        }
    }

    /** Include unchanged media fields when checking whether the form was fully saved. */
    protected function includeStableAutosaveUploads(array &$covered): void
    {
        foreach ($this->autosaveUploadFields() as $path => $field) {
            if ($field instanceof SpatieMediaLibraryFileUpload
                && ($this->autosaveUploadHashes[$path] ?? null) === $this->autosaveUploadHash($field)) {
                data_set($covered, $path, $field->getRawState());
            }
        }
    }

    /** @param mixed $state @return array<string> */
    protected function autosaveUploadStatePaths(mixed $state): array
    {
        if (! is_array($state)) {
            return is_string($state) ? [$state] : [];
        }

        $paths = [];
        foreach ($state as $value) {
            $paths = [...$paths, ...$this->autosaveUploadStatePaths($value)];
        }

        return $paths;
    }

    protected function autosaveRelativeUploadPath(string $path): string
    {
        return AutosaveFieldTree::relativePath($path, $this->getAutosaveStatePath());
    }

    /** Remove files created by a failed autosave cycle. */
    protected function discardAutosaveStoredUploads(): void
    {
        // A failing hook can run after the transaction has already rolled
        // back. Preserve the pre-rollback observation captured by
        // getAutosaveData()/writeAutosave instead of replacing it with the
        // now-invisible media rows.
        if ($this->autosaveExternalMediaAfter === []) {
            $this->captureAutosaveExternalMediaAfter();
        }

        foreach ($this->autosaveStoredUploadPaths as $path => $files) {
            $field = $this->autosaveUploadFields()[$path] ?? null;

            if ($field === null || $files === []) {
                continue;
            }

            $field->getDisk()->delete($files);
        }

        $this->autosaveStoredUploadPaths = [];
        $this->rollbackAutosaveExternalMedia();
    }

    /** Remove only the files belonging to upload fields omitted by a mutator. */
    protected function discardAutosaveStoredUploadPaths(array $paths): void
    {
        foreach ($paths as $path) {
            $files = $this->autosaveStoredUploadPaths[$path] ?? [];
            $field = $this->autosaveUploadFields()[$path] ?? null;

            if ($field !== null && $files !== []) {
                $field->getDisk()->delete($files);
            }

            unset($this->autosaveStoredUploadPaths[$path]);
        }
    }

    /** Keep files after the database and relationship writes have committed. */
    protected function commitAutosaveStoredUploads(): void
    {
        $this->autosaveStoredUploadPaths = [];
        $this->captureAutosaveExternalMediaAfter();
        $this->autosaveExternalMediaBaseline = $this->autosaveExternalMediaAfter;
        $this->autosaveRichEditorAttachmentBaseline = $this->captureAutosaveRichEditorAttachments();
        $this->autosaveExternalMediaBaselineCaptured = true;
        $this->autosaveExternalMediaAfter = [];
        $this->autosaveExternalMediaBackups = [];
    }

    /** Validate one upload field using the rules declared by Filament. */
    protected function validateAutosaveUpload(BaseFileUpload $field): bool
    {
        $rules = [];
        $field->dehydrateValidationRules($rules);
        $state = [];
        data_set($state, $field->getStatePath(), $field->getRawState());

        return ! Validator::make($state, $rules)->fails();
    }

    /**
     * Add pending Spatie raw state for hooks and rules, although it is not a
     * record column. Standard upload fields remain in the dehydrated column data.
     *
     * @return array<string, mixed>
     */
    protected function autosavePersistenceData(): array
    {
        $data = array_diff_key(
            $this->prepareAutosavePayload($this->getAutosaveData()),
            $this->autosaveBlockedUploadColumns,
        );
        foreach ($this->autosavePendingUploads as $path => $field) {
            if ($field instanceof SpatieMediaLibraryFileUpload) {
                $data[$path] = $field->getRawState() ?? [];
            }
        }

        return $data;
    }

    /** Whether a changed upload still needs persistence in this request. */
    protected function hasPendingAutosavePersistence(): bool
    {
        return $this->autosavePendingUploads !== [];
    }
}
