<?php

namespace Lenorix\FilamentAutosave;

use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

/**
 * Adds Edit-page autosave support for Filament file-upload components.
 *
 * This is an internal companion trait and should not be added directly to a
 * page. Use {@see HasAutosave}; it composes this trait with the common
 * autosave lifecycle and supplies the methods used here, such as
 * `getAutosaveFields()` and `prepareAutosavePayload()`.
 *
 * Supported components are column-backed `FileUpload` fields, top-level
 * `SpatieMediaLibraryFileUpload` fields, and both kinds inside a relationship
 * `Repeater` row that is being written in the same cycle. The trait
 * tracks upload state by hash, validates changed uploads, and leaves unchanged
 * upload fields alone. Media in a non-relationship container (a JSON repeater,
 * for example) remains an explicit-save concern because every row would share
 * one media collection. Upload operations also do not participate in column
 * Undo because filesystem changes cannot be rolled back by a database
 * transaction.
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

    /**
     * Request-local on purpose: the baseline is what THIS cycle found before
     * it dehydrated the form, so a failed cycle rolls back only the media it
     * created. Carried across requests it would date from page load, and a
     * no-write cycle would delete everything another editor added since.
     */
    protected bool $autosaveExternalMediaBaselineCaptured = false;

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

    /** @var array<string, string> Durable cleanup tokens keyed by field path. */
    protected array $autosaveUploadLedgerTokens = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    protected array $autosaveExternalMediaAfter = [];

    /** @var array<string, array<string, mixed>> */
    protected array $autosaveExternalUndoBaseline = [];

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
        $this->autosaveExternalUndoBaseline = $this->autosaveExternalUndoManager()->snapshot(
            $this->autosaveExternalUndoFields(),
        );
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

        $entries = [];
        $records = [];

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
            $entries[$key] = [$record, $collection];
            $records[spl_object_id($record)] = $record;
        }

        $this->reloadAutosaveExternalMediaRelation($records);

        foreach ($entries as $key => [$record, $collection]) {
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

    protected function autosaveExternalUndoManager(): AutosaveExternalUndoManager
    {
        return app(AutosaveExternalUndoManager::class);
    }

    /**
     * External-undo candidates among the given upload and relationship
     * components. Pass `null` for both to consider every field in the form
     * (the baseline and Undo-time callers need that); a write cycle passes
     * the components it actually touched, and an empty array there means
     * "none" -- an untouched FileUpload must not disable Undo for a
     * column-only save.
     *
     * @return array<string, object>
     */
    protected function autosaveExternalUndoFields(?array $uploads = null, ?array $relationships = null): array
    {
        if ($uploads === null && $relationships === null) {
            $uploads = $this->autosaveUploadFields();
            $relationships = method_exists($this, 'autosaveRelationshipFields')
                ? $this->autosaveRelationshipFields()
                : [];
        }

        $uploads ??= [];
        $relationships ??= [];

        $fields = [];

        foreach ($uploads as $path => $field) {
            if (is_object($field)) {
                $fields[(string) $path] = $field;
            }
        }

        foreach ($relationships as $path => $fieldSet) {
            foreach ($fieldSet as $field) {
                if (! is_object($field)) {
                    continue;
                }

                if ($field instanceof RichEditor
                    && (! method_exists($field, 'getFileAttachmentProvider') || $field->getFileAttachmentProvider() === null)) {
                    continue;
                }

                if (! $field instanceof BaseFileUpload
                    && ! $field instanceof RichEditor
                    && $this->autosaveExternalUndoManager()->adapterFor($field) === null) {
                    continue;
                }

                $fieldPath = method_exists($this, 'autosaveRelativeFieldPath')
                    ? ($this->autosaveRelativeFieldPath($field) ?? $path)
                    : $path;
                $fields[$fieldPath] = $field;
            }
        }

        return $fields;
    }

    /** @param array<string, object> $fields */
    protected function autosaveExternalUndoSnapshots(array $fields): array
    {
        $snapshots = [];

        foreach ($fields as $path => $field) {
            if (isset($this->autosaveExternalUndoBaseline[$path])) {
                $snapshots[$path] = $this->autosaveExternalUndoBaseline[$path];
            }
        }

        return $snapshots !== [] ? $snapshots : $this->autosaveExternalUndoManager()->snapshot($fields);
    }

    /** @param array<string, object> $fields */
    protected function autosaveExternalUndoHasUnsupported(array $fields): bool
    {
        return $fields !== [] && $this->autosaveExternalUndoManager()->hasUnsupported($fields);
    }

    /** @param array<string, array<string, mixed>> $snapshots @param array<string, object> $fields */
    protected function autosaveExternalUndoMatches(array $snapshots, array $fields): bool
    {
        return $snapshots === [] || $this->autosaveExternalUndoManager()->matches($snapshots, $fields);
    }

    /** @param array<string, array<string, mixed>> $snapshots @param array<string, object> $fields */
    protected function restoreAutosaveExternalUndo(array $snapshots, array $fields): void
    {
        if ($snapshots !== []) {
            $this->autosaveExternalUndoManager()->restore($snapshots, $fields);
        }
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

    /**
     * Reload the `media` relation on every record a snapshot will read.
     *
     * getMedia() serves the relation Filament loaded once at hydration, so
     * without a reload a snapshot taken right after Spatie wrote a row would
     * still look like mount time. Records are grouped by class so each group
     * costs one `whereIn` query instead of one query per field.
     *
     * @param  array<int, object>  $records
     */
    protected function reloadAutosaveExternalMediaRelation(array $records): void
    {
        $groups = [];

        foreach ($records as $record) {
            if ($record instanceof Model) {
                $groups[$record::class][] = $record;
            } elseif (method_exists($record, 'load')) {
                $record->load('media');
            }
        }

        foreach ($groups as $group) {
            $group[0]->newCollection($group)->load('media');
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

        // Filament only wraps saves in a DB transaction when the host app
        // opts in (`Panel::databaseTransactions()`); it is off by default.
        // Without one, Spatie's row insert already committed on its own, so
        // deleting only the file would leave a Media row pointing at nothing.
        if (is_string($media['uuid'] ?? null) && class_exists(SpatieMedia::class)) {
            try {
                SpatieMedia::where('uuid', $media['uuid'])->delete();
            } catch (\Throwable) {
                // Best effort: a transaction-wrapped host may have already
                // rolled this row back, or the media table may be unreachable.
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
            $nested = str_contains($path, '.');
            $related = $nested && $this->autosaveUploadInRelationship($path);

            if ($this->autosavePathExcluded($path)) {
                continue;
            }

            if (($this->autosaveUploadHashes[$path] ?? null) === $this->autosaveUploadHash($field)) {
                continue;
            }

            // Media in a row that does not exist yet is attached by the
            // relationship component once it has created the row.
            if ($media && $related && ! $this->autosaveUploadRecordExists($field)) {
                continue;
            }

            // Media in a JSON (non-relationship) container hangs off the parent
            // record: unless every row resolves its own collection, one row's
            // deleteAbandonedFiles() would wipe the others' media.
            if (($media && $nested && ! $related && ! $this->autosaveRowMediaCollectionsAreDistinct($path, $field))
                || $field->isDisabled() || $field->isHidden()
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

            // Relationship state is not a column; Filament's form validation
            // decides whether the owning relationship is written at all.
            if (! array_key_exists($top, $safe) && ! $this->autosaveUploadInRelationship($path)) {
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

            // Once validation has dropped the owning relationship, storing the
            // file would re-create a partial row state that the relationship
            // component would then treat as the complete set of rows.
            $owner = $this->autosaveUploadRelationshipOwner($path);

            if ($owner !== null && ! data_has($data, $owner)) {
                continue;
            }

            $before = $this->autosaveUploadStatePaths($field->getRawState());
            $field->saveUploadedFiles();
            $after = $this->autosaveUploadStatePaths($field->getRawState());
            $newPaths = array_values(array_diff($after, $before));

            if ($newPaths !== []) {
                $this->autosaveStoredUploadPaths[$path] = $newPaths;
                $token = app(AutosaveUploadLedger::class)->register(
                    array_map(fn (string $storedPath): array => [
                        'disk' => $field->getDiskName(),
                        'path' => $storedPath,
                    ], $newPaths),
                );
                $this->autosaveUploadLedgerTokens[$path] = $token;
            }

            if ($newPaths !== []) {
                $storedState = $field->getRawState();

                // A relationship row is dehydrated by its own schema, which
                // applies the component's cast; only column payloads need the
                // scalar/list form here.
                if (is_array($storedState) && ! $this->autosaveUploadInRelationship($path)) {
                    $storedState = array_values($storedState);
                    $storedState = $field->isMultiple()
                        ? $this->mergeAutosaveUploadedPaths($field, $path, $storedState)
                        : ($storedState[0] ?? null);
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

            // Media inside a relationship row stays in that row's state: the
            // relationship component later pushes this state back into the
            // form, and a missing key would read as "remove every file".
            // Elsewhere it is persisted through its own callback, never as
            // column data, so drop it by path (a JSON-repeater row keeps its
            // other keys).
            if (! $this->autosaveUploadInRelationship($path)) {
                $this->forgetAutosavePath($data, $path);
            }
        }

        return $uploads;
    }

    /**
     * Whether every instance of a media field nested in a non-relationship
     * container resolves a collection of its own on the parent record.
     *
     * A JSON repeater's rows have no record; their `SpatieMediaLibraryFileUpload`
     * fields all attach to the parent, and saving one row deletes any media
     * of that collection the row does not list. The supported pattern gives
     * each row a persisted UUID and a `collection()` closure derived from it.
     * The field is allowed only when all sibling instances resolve distinct,
     * non-empty collections that no top-level media field on the same record
     * uses; otherwise it stays blocked and its container is reported pending.
     */
    protected function autosaveRowMediaCollectionsAreDistinct(string $path, SpatieMediaLibraryFileUpload $field): bool
    {
        $siblings = [];

        foreach ($this->getAutosaveFields() as $fields) {
            if (in_array($field, $fields, true)) {
                $siblings = $fields;

                break;
            }
        }

        if ($siblings === []) {
            return false;
        }

        $collections = [];

        foreach ($siblings as $sibling) {
            if (! $sibling instanceof SpatieMediaLibraryFileUpload) {
                return false;
            }

            $collection = $sibling->getCollection();

            if (! is_string($collection) || $collection === '' || $collection === 'default') {
                return false;
            }

            $collections[] = $collection;
        }

        if (count(array_unique($collections)) !== count($collections)) {
            return false;
        }

        $record = $field->getRecord();
        $recordKey = is_object($record) && method_exists($record, 'getKey')
            ? $record::class.':'.$record->getKey()
            : null;

        foreach ($this->autosaveUploadFields() as $otherPath => $other) {
            if (str_contains($otherPath, '.') || ! $other instanceof SpatieMediaLibraryFileUpload) {
                continue;
            }

            $otherRecord = $other->getRecord();
            $otherKey = is_object($otherRecord) && method_exists($otherRecord, 'getKey')
                ? $otherRecord::class.':'.$otherRecord->getKey()
                : null;

            if ($otherKey === $recordKey && in_array($other->getCollection() ?? 'default', $collections, true)) {
                return false;
            }
        }

        return true;
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
            || array_key_exists(AutosaveFieldTree::topLevelKey($path), $data)
            || $this->autosaveUploadInRelationship($path);
    }

    /**
     * Relationship patterns whose components are persisted in this cycle.
     * Uploads nested under one of them are owned by the related record.
     *
     * @return array<int, string>
     */
    protected function autosaveUploadRelationshipPatterns(): array
    {
        return [];
    }

    /** Whether a nested upload path sits inside a relationship being persisted. */
    protected function autosaveUploadInRelationship(string $path): bool
    {
        return $this->autosaveUploadRelationshipOwner($path) !== null;
    }

    /** Concrete path of the closest relationship component that owns a nested upload. */
    protected function autosaveUploadRelationshipOwner(string $path): ?string
    {
        $segments = explode('.', $path);
        $patterns = $this->autosaveUploadRelationshipPatterns();
        usort($patterns, fn (string $a, string $b): int => substr_count($b, '.') <=> substr_count($a, '.'));

        foreach ($patterns as $pattern) {
            $depth = substr_count($pattern, '.') + 1;
            $owner = implode('.', array_slice($segments, 0, $depth));

            if ($depth < count($segments) && AutosaveFieldTree::matches($owner, $pattern)) {
                return $owner;
            }
        }

        return null;
    }

    /** Whether any changed upload lives inside a relationship row this cycle writes. */
    protected function autosaveRelationshipUploadsChanged(): bool
    {
        foreach ($this->autosaveUploadFields() as $path => $field) {
            if ($this->autosaveUploadInRelationship($path)
                && ($this->autosaveUploadHashes[$path] ?? null) !== $this->autosaveUploadHash($field)) {
                return true;
            }
        }

        return false;
    }

    protected function autosaveUploadRecordExists(BaseFileUpload $field): bool
    {
        $record = $field->getRecord();

        return $record instanceof Model && $record->exists;
    }

    /**
     * Persist media-library uploads through their relationship callback and
     * journal every file they create the moment its `media` row exists.
     *
     * Spatie saves the row first (the path is derived from its id) and copies
     * the file afterwards, so listening to the model's `created` event puts
     * the path in the ledger before the file reaches disk: a process killed
     * anywhere after that point still leaves a durable trail for pruning,
     * and one killed before it never wrote a file. Standard `FileUpload`
     * files are journaled in `storeAutosavePendingUploads()` instead,
     * because that is where they are written.
     */
    protected function persistAutosaveUploadRelationships(array $uploads): void
    {
        $journal = app(AutosaveMediaJournal::class);

        foreach ($uploads as $path => $field) {
            if (! $field instanceof SpatieMediaLibraryFileUpload) {
                continue;
            }

            $record = method_exists($field, 'getRecord') ? $field->getRecord() : null;
            $collection = $this->autosaveExternalMediaCollection($field);

            if (! $record instanceof Model || $collection === null) {
                $field->saveRelationships();

                continue;
            }

            $token = $journal->capture(
                $record,
                $collection,
                $this->autosaveUploadLedgerTokens[$path] ?? null,
                fn () => $field->saveRelationships(),
            );

            if ($token !== null) {
                $this->autosaveUploadLedgerTokens[$path] = $token;
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

    /**
     * Re-add column paths that a fresh upload session dropped from the field
     * state but that still exist on disk, so appending a file never wipes the
     * other files already stored in the column.
     *
     * @param  array<int, string>  $stored
     * @return array<int, string>
     */
    protected function mergeAutosaveUploadedPaths(BaseFileUpload $field, string $path, array $stored): array
    {
        $record = method_exists($this, 'getRecord') ? $this->getRecord() : null;

        if ($record === null || ! method_exists($record, 'getAttribute')) {
            return $stored;
        }

        $recordPath = method_exists($this, 'getAutosaveStatePath')
            ? $this->autosaveRelativeUploadPath($path)
            : $path;
        $existingState = str_contains($recordPath, '.')
            ? data_get($record->toArray(), $recordPath)
            : $record->getAttribute($recordPath);

        foreach ($this->autosaveUploadStatePaths($existingState) as $existing) {
            if (! in_array($existing, $stored, true) && $field->getDisk()->exists($existing)) {
                $stored[] = $existing;
            }
        }

        return $stored;
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

            if (isset($this->autosaveUploadLedgerTokens[$path])) {
                app(AutosaveUploadLedger::class)->rollback($this->autosaveUploadLedgerTokens[$path]);
            } else {
                $field->getDisk()->delete($files);
            }
        }

        // Spatie tokens registered by persistAutosaveUploadRelationships()
        // have no matching autosaveStoredUploadPaths entry to route them
        // through the loop above; their files are removed by
        // rollbackAutosaveExternalMedia() below, so just drop the entry.
        foreach ($this->autosaveUploadLedgerTokens as $token) {
            app(AutosaveUploadLedger::class)->forget($token);
        }

        $this->autosaveStoredUploadPaths = [];
        $this->autosaveUploadLedgerTokens = [];
        $this->rollbackAutosaveExternalMedia();
    }

    /** Remove only the files belonging to upload fields omitted by a mutator. */
    protected function discardAutosaveStoredUploadPaths(array $paths): void
    {
        foreach ($paths as $path) {
            $files = $this->autosaveStoredUploadPaths[$path] ?? [];
            $field = $this->autosaveUploadFields()[$path] ?? null;

            if ($field !== null && $files !== []) {
                if (isset($this->autosaveUploadLedgerTokens[$path])) {
                    app(AutosaveUploadLedger::class)->rollback($this->autosaveUploadLedgerTokens[$path]);
                } else {
                    $field->getDisk()->delete($files);
                }
            }

            unset($this->autosaveStoredUploadPaths[$path]);
            unset($this->autosaveUploadLedgerTokens[$path]);
        }
    }

    /** Keep files after the database and relationship writes have committed. */
    protected function commitAutosaveStoredUploads(): void
    {
        $ledger = app(AutosaveUploadLedger::class);

        if ($this->autosaveUploadLedgerTokens !== [] && DB::connection()->transactionLevel() > 0) {
            $tokens = $this->autosaveUploadLedgerTokens;

            DB::afterCommit(function () use ($ledger, $tokens): void {
                foreach ($tokens as $token) {
                    $ledger->commit($token);
                }

                $this->finalizeAutosaveStoredUploads();
            });

            return;
        }

        foreach ($this->autosaveUploadLedgerTokens as $token) {
            $ledger->commit($token);
        }

        $this->finalizeAutosaveStoredUploads();
    }

    /** Reset request-local upload state once the owning transaction commits. */
    protected function finalizeAutosaveStoredUploads(): void
    {
        $this->autosaveStoredUploadPaths = [];
        $this->autosaveUploadLedgerTokens = [];
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
                data_set($data, $path, $field->getRawState() ?? []);
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
