<?php

namespace Lenorix\FilamentAutosave;

use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Resources\Events\RecordSaved;
use Filament\Resources\Events\RecordUpdated;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Livewire\Attributes\Locked;

trait HasAutosave
{
    use HasAutosaveBase;
    use HasAutosaveUploads {
        HasAutosaveUploads::prepareAutosavePersistence insteadof HasAutosaveBase;
        HasAutosaveUploads::prepareAutosavePersistence as prepareAutosaveUploadsPersistence;
        HasAutosaveUploads::hasPendingAutosavePersistence insteadof HasAutosaveBase;
        HasAutosaveUploads::hasPendingAutosavePersistence as hasPendingAutosaveUploadsPersistence;
        HasAutosaveUploads::autosavePersistenceData insteadof HasAutosaveBase;
        HasAutosaveUploads::autosavePersistenceData as autosaveUploadsPersistenceData;
        HasAutosaveUploads::discardAutosaveStoredUploads insteadof HasAutosaveBase;
        HasAutosaveUploads::commitAutosaveStoredUploads insteadof HasAutosaveBase;
    }

    #[Locked]
    public bool $autosaveCanUndo = false;

    /** @var array<string, string>|null Hashes survive Livewire requests without retaining values. */
    #[Locked]
    public ?array $autosaveFieldHashes = null;

    #[Locked]
    public string $autosaveObservedHash = '';

    /** @var array<string, string> Raw hashes for relationship-backed fields. */
    #[Locked]
    public array $autosaveRelationshipHashes = [];

    /** @var array<string, string> Hashes captured before post-save hooks can edit the form. */
    protected array $autosaveWrittenFieldHashes = [];

    /** @var array<string, array<object>> Relationship fields changed this request. */
    protected array $autosavePendingRelationships = [];

    /** @var array<string, mixed> Values refreshed from the record for the status event. */
    protected array $autosaveRefreshState = [];

    /** Undo has been prepared but the surrounding transaction has not committed. */
    protected bool $autosaveUndoPrepared = false;

    public function dehydrateHasAutosave(): void
    {
        if (! $this->isAutosaveEnabled()) {
            return;
        }

        $state = $this->prepareAutosavePayload($this->data ?? []);
        $files = [];
        foreach ($this->autosaveUploadFields() as $path => $field) {
            if (! $this->autosavePathExcluded($path)) {
                $files[$path] = $this->autosaveUploadHash($field);
            }
        }
        $hash = $this->autosaveStore()->snapshotHash(['state' => $state, 'files' => $files]);
        $this->autosaveObservedHash = $hash;
    }

    public function mountHasAutosave(): void
    {
        if (! $this->initializeAutosaveState()) {
            return;
        }

        $this->autosaveDebounceMs = $this->getAutosaveDebounce();

        $this->resetAutosaveHashes();
    }

    protected function autosaveDirtyOnly(): bool
    {
        return (bool) config('filament-autosave.dirty_only', true);
    }

    protected function shouldRefreshAutosaveUnchangedFields(): bool
    {
        return $this->autosaveDirtyOnly()
            && (bool) config('filament-autosave.refresh_unchanged_fields', true);
    }

    /** @param array<string, mixed> $data */
    protected function filterAutosavePayload(array $data): array
    {
        if (! $this->autosaveDirtyOnly() || $this->autosaveFieldHashes === null) {
            return $data;
        }

        return array_filter($data, fn ($value, $key): bool => ($this->autosaveFieldHashes[$key] ?? null) !== $this->hashAutosaveValue($value),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    protected function resetAutosaveHashes(): void
    {
        $data = $this->prepareAutosavePayload($this->getAutosaveData());
        $this->autosaveFieldHashes = $this->hashAutosaveFields($data);
        $this->autosaveSnapshotHash = $this->autosaveStore()->snapshotHash($data);
        $this->resetAutosaveUploadHashes();
        $this->resetAutosaveRelationshipHashes();
    }

    protected function resetAutosaveRefreshState(): void
    {
        $this->autosaveRefreshState = [];
    }

    /** @return array<string, mixed> */
    protected function getAutosaveRefreshState(): array
    {
        return $this->autosaveRefreshState;
    }

    /**
     * Refresh clean top-level fields after persistence and leave local edits intact.
     *
     * Filament's partial form refresh applies casts and fill hooks, so the
     * response contains the same representation users see after a normal fill.
     */
    protected function refreshAutosaveUnchangedFields(): void
    {
        $this->autosaveRefreshState = [];

        if (! $this->shouldRefreshAutosaveUnchangedFields()
            || $this->autosaveFieldHashes === null
            || ! method_exists($this, 'refreshFormData')) {
            return;
        }

        $record = $this->getRecord();

        if (! method_exists($record, 'attributesToArray')) {
            return;
        }

        $current = $this->prepareAutosavePayload($this->getAutosaveData());
        $dirty = [];

        foreach ($current as $path => $value) {
            if (($this->autosaveFieldHashes[$path] ?? null) !== $this->hashAutosaveValue($value)) {
                $dirty[AutosaveFieldTree::topLevelKey($path)] = true;
            }
        }

        foreach ($this->autosaveRelationshipFields() as $path => $fields) {
            $dirty[AutosaveFieldTree::topLevelKey($path)] = true;
        }

        foreach ($this->autosaveUploadFields() as $path => $field) {
            $dirty[AutosaveFieldTree::topLevelKey($path)] = true;
        }

        $attributes = $record->attributesToArray();
        $paths = [];

        foreach (array_keys($current) as $path) {
            $top = AutosaveFieldTree::topLevelKey($path);

            if (isset($dirty[$top])
                || $this->autosavePathExcluded($top)
                || ! array_key_exists($top, $attributes)) {
                continue;
            }

            $paths[$top] = true;
        }

        if ($paths === []) {
            return;
        }

        try {
            $this->refreshFormData(array_keys($paths));
        } catch (\Throwable $e) {
            Log::warning('Autosave unchanged-field refresh failed', ['exception' => $e::class]);

            return;
        }

        $refreshed = $this->prepareAutosavePayload($this->getAutosaveData());

        foreach (array_keys($paths) as $path) {
            if (! array_key_exists($path, $refreshed)) {
                continue;
            }

            $this->autosaveRefreshState[$path] = $refreshed[$path];
            $this->autosaveFieldHashes[$path] = $this->hashAutosaveValue($refreshed[$path]);
        }
    }

    /**
     * Relationship components often dehydrate(false), so their state needs a
     * separate hash in order to trigger autosave without becoming a column.
     *
     * @return array<string, array<object>>
     */
    protected function autosaveRelationshipFields(): array
    {
        $relationships = [];

        foreach ($this->getAutosaveFields() as $path => $fields) {
            foreach ($fields as $field) {
                if ((method_exists($field, 'isDisabled') && $field->isDisabled())
                    || (method_exists($field, 'isHidden') && $field->isHidden())) {
                    continue;
                }

                $relationship = method_exists($field, 'getRelationship') ? $field->getRelationship() : null;
                $hasRelationship = ((method_exists($field, 'hasRelationship') && $field->hasRelationship())
                    || $relationship !== null)
                    && (! ($relationship instanceof BelongsTo) || $relationship instanceof MorphTo);
                $isRichEditor = $field instanceof RichEditor;

                if ($hasRelationship || $isRichEditor) {
                    $relationships[$path][] = $field;
                }
            }
        }

        return $relationships;
    }

    /**
     * Hash every component instance represented by a normalized path.
     *
     * A repeater/builders' `items.*.relation` path is shared by all rows. The
     * component instances are nevertheless independent, so hashing only the
     * first one makes edits in later rows invisible to autosave.
     *
     * @param  object|array<int, object>  $fields
     */
    protected function autosaveRelationshipHash(object|array $fields): string
    {
        $fields = is_array($fields) ? $fields : [$fields];
        $states = [];

        foreach ($fields as $index => $field) {
            $path = method_exists($field, 'getStatePath')
                ? (string) ($field->getStatePath() ?? '')
                : (string) $index;

            $states[$path !== '' ? $path : (string) $index] = $field->getRawState();
        }

        return $this->hashAutosaveValue($states);
    }

    protected function resetAutosaveRelationshipHashes(): void
    {
        $this->autosaveRelationshipHashes = [];

        foreach ($this->autosaveRelationshipFields() as $path => $fields) {
            $this->autosaveRelationshipHashes[$path] = $this->autosaveRelationshipHash($fields);
        }
    }

    protected function prepareAutosaveRelationshipPersistence(): void
    {
        $this->autosavePendingRelationships = [];

        foreach ($this->autosaveRelationshipFields() as $path => $fields) {
            if ($this->autosavePathExcluded($path)) {
                continue;
            }

            if (($this->autosaveRelationshipHashes[$path] ?? null) !== $this->autosaveRelationshipHash($fields)) {
                $this->autosavePendingRelationships[$path] = $fields;
            }
        }
    }

    protected function prepareAutosavePersistence(): void
    {
        // Uploads nested in a relationship row are only actionable when that
        // relationship is being written, so resolve relationships first.
        $this->autosaveFieldsCache = null;
        $this->prepareAutosaveRelationshipPersistence();
        $this->prepareAutosaveUploadsPersistence();
    }

    /** @return array<int, string> */
    protected function autosaveUploadRelationshipPatterns(): array
    {
        return array_keys($this->autosavePendingRelationships);
    }

    /** @return array<string, mixed> */
    protected function autosavePersistenceData(): array
    {
        $data = $this->autosaveUploadsPersistenceData();

        foreach ($this->autosavePendingRelationships as $path => $fields) {
            foreach ($fields as $field) {
                $fieldPath = $this->autosaveRelativeFieldPath($field) ?? $path;

                // `data_set()` does not give a useful representation for a
                // wildcard pattern. Always write to the concrete row path.
                // RichEditor content is already present as dehydrated column data.
                if (str_contains($fieldPath, '*') || $field instanceof RichEditor) {
                    continue;
                }

                data_set($data, $fieldPath, $field->getRawState());
            }
        }

        return $data;
    }

    protected function hasPendingAutosavePersistence(): bool
    {
        return $this->hasPendingAutosaveUploadsPersistence() || $this->autosavePendingRelationships !== [];
    }

    /** @return array<string, string> */
    protected function hashAutosaveFields(array $data): array
    {
        return array_map($this->hashAutosaveValue(...), $data);
    }

    /** Hash one value the same way autosave field hashes are built. */
    protected function hashAutosaveValue(mixed $value): string
    {
        return $this->autosaveStore()->snapshotHash(['value' => $value]);
    }

    /** Only acknowledge fields the persistence callback actually wrote. */
    protected function autosaveSuccessSnapshotHash(array $written): string
    {
        $current = $this->prepareAutosavePayload($this->getAutosaveData());
        $this->autosaveFieldHashes = array_replace(
            $this->autosaveFieldHashes ?? [],
            $this->autosaveWrittenFieldHashes,
        );

        foreach ($this->autosavePendingRelationships as $path => $fields) {
            $this->autosaveRelationshipHashes[$path] = $this->autosaveRelationshipHash($fields);
        }

        return $this->hashAutosaveFields($current) === $this->autosaveFieldHashes
            ? $this->autosaveStore()->snapshotHash($current)
            : '';
    }

    /** Return a field's path relative to the form state root. */
    protected function autosaveRelativeFieldPath(object $field): ?string
    {
        if (! method_exists($field, 'getStatePath') || ! filled($fieldPath = $field->getStatePath())) {
            return null;
        }

        return AutosaveFieldTree::relativePath((string) $fieldPath, $this->getAutosaveStatePath());
    }

    // Filament calls this after a successful explicit save, not after a Halt.
    protected function rememberData(): void
    {
        if (($parent = get_parent_class(self::class)) && method_exists($parent, 'rememberData')) {
            parent::rememberData();
        }

        if (! $this->isAutosaving) {
            $this->clearAutosaveValidationErrors();
            $this->resetAutosaveHashes();
            $this->autosaveValidationErrors = [];
        }
    }

    /** @return array<string, mixed> */
    protected function getAutosaveData(): array
    {
        $this->captureAutosaveExternalMediaBaseline();
        $form = $this->resolveAutosaveForm();

        if ($form === null) {
            $data = $this->stripFileUploads($this->data ?? []);
            $this->captureAutosaveExternalMediaAfter();

            return $data;
        }

        $data = $this->stripFileUploads($this->dehydrateAutosaveState($form));
        $this->captureAutosaveExternalMediaAfter();

        foreach ($this->autosaveBelongsToFields() as $path => $fields) {
            foreach ($fields as $field) {
                $fieldPath = $this->autosaveRelativeFieldPath($field) ?? $path;

                if (str_contains($fieldPath, '*')) {
                    continue;
                }

                data_set($data, $fieldPath, data_get($this->data ?? [], $fieldPath, $field->getRawState()));
            }
        }

        return $data;
    }

    /** @return array<string, array<int, object>> */
    protected function autosaveBelongsToFields(): array
    {
        $fields = [];

        foreach ($this->getAutosaveFields() as $path => $fieldSet) {
            foreach ($fieldSet as $field) {
                $relationship = method_exists($field, 'getRelationship')
                    ? $field->getRelationship()
                    : null;

                if ($relationship instanceof BelongsTo) {
                    $fields[$path][] = $field;
                }
            }
        }

        return $fields;
    }

    /**
     * Mirrors Schema::getStateSnapshot() minus validation.
     *
     * @return array<string, mixed>
     */
    protected function dehydrateAutosaveState(object $form): array
    {
        if (! method_exists($form, 'dehydrateState')) {
            return $this->normalizeStateArray($form->getRawState());
        }

        $statePath = method_exists($form, 'getStatePath') ? $form->getStatePath() : null;
        $raw = $this->normalizeStateArray($form->getRawState());

        $state = [];

        if (filled($statePath)) {
            data_set($state, $statePath, $raw);
        } else {
            $state = $raw;
        }

        // Filament uses this hook before dehydration for upload storage,
        // editor attachments, and other component-specific preparation.
        $this->callAutosaveBeforeStateDehydrated($form, $state);

        $form->dehydrateState($state);

        if (method_exists($form, 'mutateDehydratedState')) {
            $form->mutateDehydratedState($state);
        }

        $dehydrated = filled($statePath)
            ? $this->normalizeStateArray(data_get($state, $statePath))
            : $state;

        return $this->pruneToDeclaredFields($form, $dehydrated);
    }

    /**
     * Run Filament's dehydration callbacks while leaving ordinary uploads to
     * HasAutosaveUploads, which validates them before permanent storage.
     *
     * @param  array<string, mixed>  $state
     */
    protected function callAutosaveBeforeStateDehydrated(object $form, array &$state): void
    {
        if (method_exists($form, 'getFlatFields')) {
            foreach ($form->getFlatFields(withHidden: true) as $field) {
                if ($field instanceof BaseFileUpload) {
                    continue;
                }

                if (method_exists($field, 'callBeforeStateDehydrated')) {
                    $field->callBeforeStateDehydrated($state);
                }
            }

            return;
        }

        if (method_exists($form, 'callBeforeStateDehydrated')) {
            $form->callBeforeStateDehydrated($state);
        }
    }

    /**
     * Fields with no declared children may carry arbitrary JSON.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function pruneToDeclaredFields(object $form, array $state): array
    {
        if (! method_exists($form, 'getFlatFields')) {
            return $state;
        }

        return $this->pruneStateToFieldTree(
            $state,
            $this->buildAutosaveFieldTree(array_keys($this->getAutosaveFields())),
        );
    }

    public function autosave(): void
    {
        $this->performAutosave(function (array $data): array|false {
            $data = $this->storeAutosavePendingUploads($data);
            $uploads = $this->consumePendingUploads($data);

            $this->callAutosaveHook('beforeSave');

            if (method_exists($this, 'mutateFormDataBeforeSave')) {
                $data = $this->mutateFormDataBeforeSave($data);
            }

            // Keep the full state available to validation and page mutators;
            // only the final record write is reduced to dirty fields.
            $data = $this->filterAutosavePayload($data);

            $relationships = $this->resolvePendingAutosaveRelationships($data);

            $data = array_diff_key($this->dropIncompleteAutosaveContainers(
                $this->dropBlankRequiredAutosaveFields($data)
            ), $this->autosaveBlockedUploadColumns);

            $uploads = $this->filterPersistableAutosaveUploads($uploads, $data);

            if (empty($data) && empty($uploads) && empty($relationships)) {
                return false;
            }

            // A new save replaces the previous single-step Undo target. Clear
            // both stores first so a relation-only save cannot restore stale
            // columns (or vice versa).
            $this->prepareAutosaveUndo($data, $uploads, $relationships);

            return $this->completeAutosaveWrite($data, $uploads, $relationships);
        });
    }

    /**
     * Persist the write and settle everything that depends on it succeeding:
     * field hashes, expected Undo state, uploads, and notifications.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, BaseFileUpload>  $uploads
     * @param  array<string, array<object>>  $relationships
     * @return array<string, mixed>
     */
    protected function completeAutosaveWrite(array $data, array $uploads, array $relationships): array
    {
        $this->autosaveWrittenFieldHashes = $this->hashAutosaveFields(array_intersect_key(
            $this->prepareAutosavePayload($this->getAutosaveData()), $data,
        ));

        $this->writeAutosave($data, $uploads, $relationships);

        $this->getRecord()->refresh();
        $this->refreshAutosaveUnchangedFields();
        $this->storeUndoExpectedSnapshot(array_keys($data));
        $this->storeUndoExpectedRelationshipSnapshot($relationships);
        $this->acknowledgeAutosaveUploads($uploads, $data);
        $this->rememberAutosavedData($data);
        $this->afterAutosave($this->getRecord());
        // The notification is emitted by runAutosaveCycle after the
        // database transaction has committed successfully.
        $this->queueAutosaveSavedNotification();

        return $data;
    }

    /**
     * Snapshot everything this write is about to change, replacing the previous
     * single-step Undo target. Uploads never grant Undo (files cannot be rolled
     * back), and neither do relationship fields with file persistence.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, BaseFileUpload>  $uploads
     * @param  array<string, array<object>>  $relationships
     */
    protected function prepareAutosaveUndo(array $data, array $uploads, array $relationships): void
    {
        $this->clearUndoSnapshots();
        $this->autosaveUndoPrepared = true;
        $relationshipUndo = $this->captureAutosaveRelationshipUndo($relationships);
        $columnUndo = $this->storeUndoSnapshot(array_keys($data));
        $relationUndo = $this->storeUndoRelationshipSnapshot($relationshipUndo);
        $unsafeRelationshipUndo = $this->autosaveRelationshipsHaveFilePersistence($relationships);
        $this->autosaveCanUndo = $uploads === [] && ! $unsafeRelationshipUndo && ($columnUndo || $relationUndo);
    }

    /**
     * Move the raw state of each pending relationship into its components,
     * then drop those values from the column payload. Paths with no matching
     * state no longer count as pending in this write.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<object>>
     */
    protected function resolvePendingAutosaveRelationships(array &$data): array
    {
        $relationships = $this->autosavePendingRelationships;
        $missing = new \stdClass;

        foreach ($relationships as $path => $fields) {
            $resolved = false;

            foreach ($fields as $field) {
                $fieldPath = $this->autosaveRelativeFieldPath($field) ?? $path;
                $state = data_get($data, $fieldPath, $missing);

                if ($state === $missing) {
                    continue;
                }

                if (method_exists($field, 'rawState')) {
                    $field->rawState($state);
                }

                // A RichEditor is a column whose callback only manages file
                // attachments, so its content must stay in the column write.
                if (! $field instanceof RichEditor) {
                    $this->forgetAutosavePath($data, $fieldPath);
                }

                $resolved = true;
            }

            if (! $resolved) {
                unset($relationships[$path]);
            }
        }

        $this->autosavePendingRelationships = $relationships;

        return $relationships;
    }

    /**
     * Write the record columns, then let upload and relationship components
     * persist themselves, and finish with the standard save events.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, BaseFileUpload>  $uploads
     * @param  array<string, array<object>>  $relationships
     * @return array<string, mixed>
     */
    protected function writeAutosave(array $data, array $uploads, array $relationships): array
    {
        try {
            if ($data !== []) {
                $this->handleRecordUpdate($this->getRecord(), $data);
            }

            $this->persistAutosaveUploadRelationships($uploads);

            foreach ($relationships as $fields) {
                foreach ($fields as $field) {
                    $field->saveRelationships();
                }
            }

            $this->callAutosaveHook('afterSave');
            $this->dispatchAutosaveRecordEvents($data);

            return $data;
        } finally {
            // Keep a path-level record even when the DB transaction is about
            // to roll back and the newly-created media rows disappear with it.
            $this->captureAutosaveExternalMediaAfter();
        }
    }

    /**
     * Only re-baseline Filament's unsaved-changes alert once every filled field was written.
     *
     * @param  array<string, mixed>|null  $written  Pass null to reset after undo.
     */
    protected function rememberAutosavedData(?array $written = null): void
    {
        if (! method_exists($this, 'rememberData')) {
            return;
        }

        if ($written !== null && ! $this->autosaveWasLossless($written)) {
            return;
        }

        $this->rememberData();
    }

    /** @param  array<string, mixed>  $written */
    protected function autosaveWasLossless(array $written): bool
    {
        if ($this->autosavePendingRelationships !== []) {
            return false;
        }

        $state = $this->data ?? [];
        $current = $this->prepareAutosavePayload($this->getAutosaveData());
        $covered = $written;
        foreach ($current as $key => $value) {
            if (($this->autosaveFieldHashes[$key] ?? null) === $this->hashAutosaveValue($value)) {
                $covered[$key] = $value;
            }
        }
        $this->includeStableAutosaveUploads($covered);

        foreach (array_keys($this->getAutosaveFields()) as $path) {
            foreach ($this->matchAutosavePaths($state, $path) as $match) {
                if (blank(data_get($state, $match))) {
                    continue;
                }

                // Dehydration can renumber repeater rows; compare the pattern.
                if (! $this->autosavePathIsComplete($covered, $path)) {
                    return false;
                }

                break;
            }
        }

        return true;
    }

    public function undoAutosave(): void
    {
        try {
            $this->authorizeAutosaveAccess();

            // The locked flag prevents undo from an older page load.
            $snapshot = $this->autosaveUndoCached($this->getUndoCacheKey());
            $relationshipSnapshot = $this->autosaveUndoCached($this->getUndoRelationshipCacheKey());

            if ((! is_array($snapshot) || empty($snapshot))
                && (! is_array($relationshipSnapshot) || empty($relationshipSnapshot))) {
                $this->autosaveCanUndo = false;
                $this->dispatchAutosaveIdle();

                return;
            }

            $expected = $this->autosaveUndoCached($this->getUndoExpectedCacheKey());
            $expectedRelationships = $this->autosaveUndoCached($this->getUndoExpectedRelationshipCacheKey());

            if ($this->undoHasConflict($expected, $expectedRelationships)) {
                $this->resetAutosaveUndo();
                $this->dispatch(AutosaveStatus::EVENT, status: AutosaveStatus::Conflict->value);

                return;
            }

            $this->autosaveWithinTransaction(function () use ($snapshot, $relationshipSnapshot): void {
                // Keep Undo observable through the same page lifecycle as an
                // explicit Filament edit. Hooks may halt or fail, in which
                // case autosaveWithinTransaction rolls the restoration back.
                $this->callAutosaveHook('beforeValidate');
                $this->callAutosaveHook('afterValidate');
                $this->callAutosaveHook('beforeSave');

                if (is_array($snapshot) && $snapshot !== []) {
                    $this->handleRecordUpdate($this->getRecord(), $snapshot);
                }

                if (is_array($relationshipSnapshot) && $relationshipSnapshot !== []) {
                    $this->restoreAutosaveRelationshipUndo($relationshipSnapshot);
                }

                $this->callAutosaveHook('afterSave');
                $this->dispatchAutosaveRecordEvents(is_array($snapshot) ? $snapshot : []);
            });

            $this->getRecord()->refresh();

            method_exists($this, 'fillForm')
                ? $this->fillForm()
                : $this->fillAutosaveData($snapshot);

            $this->resetAutosaveHashes();
            $this->clearAutosaveValidationErrors();
            $this->autosaveValidationErrors = [];

            $this->rememberAutosavedData();

            $this->resetAutosaveUndo();

            $this->sendAutosaveSavedNotification();

            $this->dispatch(AutosaveStatus::EVENT, status: AutosaveStatus::Undone->value);
        } catch (\Throwable $e) {
            $this->handleAutosaveFailure($e, 'undo');
        }
    }

    /**
     * @param  array<string>  $fieldKeys
     * @return bool Whether undo is available.
     */
    protected function storeUndoSnapshot(array $fieldKeys): bool
    {
        $record = $this->getRecord();

        if (! $record || empty($fieldKeys)) {
            return false;
        }

        // Use real columns only; accessors and relationships cannot be restored.
        if (method_exists($record, 'getAttributes')) {
            $fieldKeys = array_values(array_intersect($fieldKeys, array_keys($record->getAttributes())));

            if (empty($fieldKeys)) {
                return false;
            }
        }

        $previous = $this->normalizeUndoSnapshot($record->only($fieldKeys));

        if (empty($previous)) {
            return false;
        }

        $this->putUndoSnapshot($this->getUndoCacheKey(), $previous);

        return true;
    }

    /** Store the values written by this autosave for optimistic Undo checks. */
    protected function storeUndoExpectedSnapshot(array $fieldKeys): void
    {
        $record = $this->getRecord();

        if ($fieldKeys === [] || ! $record || ! method_exists($record, 'only')) {
            return;
        }

        $this->putUndoSnapshot($this->getUndoExpectedCacheKey(), $this->normalizeUndoSnapshot($record->only($fieldKeys)));
    }

    /** @param array<string, mixed> $expected */
    protected function undoMatchesExpectedState(array $expected): bool
    {
        $record = $this->getRecord();

        if (! $record || ! method_exists($record, 'only')) {
            return true;
        }

        return $this->normalizeUndoSnapshot($record->only(array_keys($expected))) === $expected;
    }

    /**
     * Whether the record drifted from what this autosave wrote. Missing
     * snapshots never count as a conflict.
     *
     * @param  array<string, mixed>|null  $expected
     * @param  array<string, array<string, mixed>>|null  $expectedRelationships
     */
    protected function undoHasConflict(?array $expected, ?array $expectedRelationships): bool
    {
        return (is_array($expected) && ! $this->undoMatchesExpectedState($expected))
            || (is_array($expectedRelationships) && ! $this->undoMatchesExpectedRelationships($expectedRelationships));
    }

    /** @param array<string, array<object>> $relationships */
    protected function storeUndoExpectedRelationshipSnapshot(array $relationships): void
    {
        if ($relationships === []) {
            return;
        }

        $this->putUndoSnapshot($this->getUndoExpectedRelationshipCacheKey(), $this->captureAutosaveRelationshipUndo($relationships));
    }

    /** @param array<string, array<string, mixed>> $expected */
    protected function undoMatchesExpectedRelationships(array $expected): bool
    {
        $captured = $this->captureAutosaveRelationshipUndo($this->autosaveRelationshipFields());

        return array_intersect_key($captured, $expected) === $expected;
    }

    /**
     * Capture database state for relationship components before they are saved.
     * The snapshot is intentionally separate from column Undo data.
     *
     * @param  array<string, array<object>>  $relationships
     * @return array<string, array<string, mixed>>
     */
    protected function captureAutosaveRelationshipUndo(array $relationships): array
    {
        $snapshot = [];

        foreach ($relationships as $path => $fields) {
            foreach ($fields as $index => $field) {
                $snapshotPath = count($fields) === 1
                    ? $path
                    : ($this->autosaveRelativeFieldPath($field) ?? $path.'.'.$index);
                $captured = $this->captureAutosaveRelationshipUndoField($field);

                if ($captured !== null) {
                    $snapshot[$snapshotPath] = $captured;
                }
            }
        }

        return $snapshot;
    }

    /** @return array<string, mixed>|null */
    protected function captureAutosaveRelationshipUndoField(object $field): ?array
    {
        if (! method_exists($field, 'getRelationship')) {
            return null;
        }

        $relation = $field->getRelationship();

        return match (true) {
            $relation instanceof MorphTo => [
                'type' => 'morphTo',
                'attributes' => $this->captureMorphToAttributes($relation),
            ],
            $relation instanceof BelongsToMany => [
                'type' => 'belongsToMany',
                'rows' => $this->captureBelongsToManyRows($relation),
            ],
            $relation instanceof HasOneOrManyThrough, $relation instanceof HasOneOrMany => [
                'type' => $relation instanceof HasOneOrManyThrough ? 'hasOneOrManyThrough' : 'hasOneOrMany',
                'rows' => $this->captureHasManyRows($relation),
            ],
            default => null,
        };
    }

    /** @return array<string, mixed> */
    protected function captureMorphToAttributes(MorphTo $relation): array
    {
        $parent = $relation->getParent();
        $typeKey = $relation->getMorphType();
        $foreignKey = $relation->getForeignKeyName();

        return $this->normalizeUndoSnapshot([
            $typeKey => $parent->getAttribute($typeKey),
            $foreignKey => $parent->getAttribute($foreignKey),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    protected function captureBelongsToManyRows(BelongsToMany $relation): array
    {
        $rows = [];

        foreach ($relation->get() as $related) {
            $rows[] = [
                'key' => $related->getKey(),
                'pivot' => $related->pivot?->getAttributes() ?? [],
            ];
        }

        return $this->normalizeUndoSnapshot($rows);
    }

    /** @return array<int, array<string, mixed>> */
    protected function captureHasManyRows(HasOneOrMany|HasOneOrManyThrough $relation): array
    {
        $rows = $relation->get()->map(function ($related): array {
            $attributes = $related->getAttributes();

            // HasManyThrough adds a synthetic `laravel_through_key` select
            // alias. It is useful for hydration, but is not a real column and
            // must never be written back during Undo.
            unset($attributes['laravel_through_key']);

            return ['attributes' => $this->normalizeUndoSnapshot($attributes)];
        })->all();

        return $this->normalizeUndoSnapshot($rows);
    }

    /**
     * RichEditor providers and uploads inside relationship rows can delete or
     * create files outside the DB transaction.
     */
    protected function autosaveRelationshipsHaveFilePersistence(array $relationships): bool
    {
        foreach ($relationships as $fields) {
            foreach ($fields as $field) {
                if ($field instanceof RichEditor
                    && (! method_exists($field, 'getFileAttachmentProvider') || $field->getFileAttachmentProvider() !== null)) {
                    return true;
                }
            }
        }

        return $this->autosaveRelationshipUploadsChanged();
    }

    /** @param  array<string, array<string, mixed>>  $snapshot */
    protected function storeUndoRelationshipSnapshot(array $snapshot): bool
    {
        if ($snapshot === []) {
            return false;
        }

        $this->putUndoSnapshot($this->getUndoRelationshipCacheKey(), $snapshot);

        return true;
    }

    protected function clearUndoSnapshots(): void
    {
        Cache::forget($this->getUndoCacheKey());
        Cache::forget($this->getUndoRelationshipCacheKey());
        Cache::forget($this->getUndoExpectedCacheKey());
        Cache::forget($this->getUndoExpectedRelationshipCacheKey());
    }

    /** Wipe the Undo target; the underlying state can no longer be restored. */
    protected function resetAutosaveUndo(): void
    {
        $this->clearUndoSnapshots();
        $this->autosaveCanUndo = false;
        $this->autosaveUndoPrepared = false;
    }

    /** @param  array<string, array<string, mixed>>  $snapshot */
    protected function restoreAutosaveRelationshipUndo(array $snapshot): void
    {
        $fieldsByPath = $this->autosaveRelationshipFields();

        foreach ($snapshot as $path => $state) {
            $field = $this->autosaveRelationshipFieldForPath($path, $fieldsByPath);

            if (! $field || ! method_exists($field, 'getRelationship')) {
                continue;
            }

            $relation = $field->getRelationship();

            match (true) {
                $relation instanceof MorphTo => $this->restoreMorphToUndo($relation, $state['attributes'] ?? []),
                $relation instanceof BelongsToMany => $this->restoreBelongsToManyUndo($relation, $state['rows'] ?? []),
                $relation instanceof HasOneOrManyThrough => $this->restoreHasManyThroughUndo($relation, $state['rows'] ?? []),
                $relation instanceof HasOneOrMany => $this->restoreHasManyUndo($relation, $state['rows'] ?? []),
                default => null,
            };
        }
    }

    /** @param array<string, mixed> $attributes */
    protected function restoreMorphToUndo(MorphTo $relation, array $attributes): void
    {
        if ($attributes !== []) {
            $relation->getParent()->forceFill($attributes)->save();
        }
    }

    /** @param array<int, array<string, mixed>> $rows */
    protected function restoreBelongsToManyUndo(BelongsToMany $relation, array $rows): void
    {
        $ids = [];

        foreach ($rows as $row) {
            $ids[$row['key']] = $row['pivot'] ?? [];
        }

        $relation->sync($ids);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function restoreHasManyThroughUndo(HasOneOrManyThrough $relation, array $rows): void
    {
        $related = $relation->getRelated();
        $keyName = $related->getKeyName();
        $original = $this->autosaveRelatedRowsByKey($rows, $keyName);

        // A through relation has no save/sync operation of its own.
        // Restore the complete related set explicitly: delete rows
        // introduced by the autosave, update rows that survived, and
        // recreate rows that the autosave deleted.
        $this->deleteAutosaveRowsMissingFrom($relation, $original);

        foreach ($original as $attributes) {
            $model = $related->newQuery()->whereKey($attributes[$keyName])->first()
                ?? $related->newInstance();

            $model->forceFill($attributes);
            $model->save();
        }
    }

    /** @param array<int, array<string, mixed>> $rows */
    protected function restoreHasManyUndo(HasOneOrMany $relation, array $rows): void
    {
        $related = $relation->getRelated();
        $keyName = $related->getKeyName();
        $original = $this->autosaveRelatedRowsByKey($rows, $keyName);

        $this->deleteAutosaveRowsMissingFrom($relation, $original);

        foreach ($original as $attributes) {
            $model = $related->newQuery()->whereKey($attributes[$keyName])->first()
                ?? $related->newInstance();
            $model->forceFill($attributes);
            $relation->save($model);
        }
    }

    /** Delete current rows that were not part of the original snapshot. */
    protected function deleteAutosaveRowsMissingFrom(object $relation, array $original): void
    {
        foreach ($relation->get() as $current) {
            if (! array_key_exists((string) $current->getKey(), $original)) {
                $current->delete();
            }
        }
    }

    /**
     * Index snapshot rows by their primary key, skipping rows without one.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    protected function autosaveRelatedRowsByKey(array $rows, string $keyName): array
    {
        $original = [];

        foreach ($rows as $row) {
            $attributes = $row['attributes'] ?? [];
            $key = (string) ($attributes[$keyName] ?? '');

            if ($key === '') {
                continue;
            }

            $original[$key] = $attributes;
        }

        return $original;
    }

    /**
     * Resolve both new concrete-row snapshots and the legacy normalized key
     * used for a single relationship component.
     *
     * @param  array<string, array<object>>  $fieldsByPath
     */
    protected function autosaveRelationshipFieldForPath(string $path, array $fieldsByPath): ?object
    {
        if (isset($fieldsByPath[$path][0])) {
            return $fieldsByPath[$path][0];
        }

        foreach ($fieldsByPath as $pattern => $fields) {
            foreach ($fields as $field) {
                if (($this->autosaveRelativeFieldPath($field) ?? $pattern) === $path) {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * Turn dates and enums into scalars without losing JSON-cast arrays.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizeUndoSnapshot(array $data): array
    {
        return AutosaveStore::normalizeScalars($data);
    }

    protected function getUndoCacheKey(): string
    {
        return $this->autosaveStore()->undoCacheKey(static::class, $this->getRecord()?->getKey());
    }

    protected function getUndoRelationshipCacheKey(): string
    {
        return $this->getUndoCacheKey().':relationships';
    }

    protected function getUndoExpectedCacheKey(): string
    {
        return $this->getUndoCacheKey().':expected';
    }

    protected function getUndoExpectedRelationshipCacheKey(): string
    {
        return $this->getUndoCacheKey().':expected-relationships';
    }

    /** Read an undo snapshot only when this page load still owns the feature. */
    protected function autosaveUndoCached(string $key): ?array
    {
        return $this->autosaveCanUndo ? Cache::get($key) : null;
    }

    protected function getUndoTtlMinutes(): int
    {
        return AutosavePlugin::resolve()->getUndoCacheTtl();
    }

    protected function putUndoSnapshot(string $key, array $value): void
    {
        Cache::put($key, $value, now()->addMinutes($this->getUndoTtlMinutes()));
    }

    /** Hook called after a successful Edit-page save. */
    protected function afterAutosave(object $record): void {}

    protected function runAutosavePersistence(callable $persist, array $data): mixed
    {
        return $persist($data);
    }

    protected function runAutosaveCycle(callable $cycle): mixed
    {
        $fieldHashes = $this->autosaveFieldHashes;
        $snapshotHash = $this->autosaveSnapshotHash;
        $this->autosaveUndoPrepared = false;
        $this->clearQueuedAutosaveNotification();

        try {
            $result = $this->autosaveWithinTransaction($cycle);
            $this->autosaveUndoPrepared = false;
            $this->flushAutosaveSavedNotification();

            return $result;
        } catch (\Throwable $e) {
            // A failed commit must never leave a notification queued for a
            // later request.
            $this->autosaveFieldHashes = $fieldHashes;
            $this->autosaveSnapshotHash = $snapshotHash;

            if ($this->autosaveUndoPrepared) {
                $this->resetAutosaveUndo();
            }

            $this->clearQueuedAutosaveNotification();

            throw $e;
        }
    }

    protected function sendAutosaveSavedNotification(): void
    {
        if (! method_exists($this, 'getSavedNotification')) {
            return;
        }

        $notification = $this->getSavedNotification();

        if ($notification !== null && method_exists($notification, 'send')) {
            $notification->send();
        }
    }

    /** Fire the record events Filament Edit pages emit after a save. */
    protected function dispatchAutosaveRecordEvents(array $data): void
    {
        Event::dispatch(RecordUpdated::class, [
            'record' => $this->getRecord(),
            'data' => $data,
            'page' => $this,
        ]);
        Event::dispatch(RecordSaved::class, [
            'record' => $this->getRecord(),
            'data' => $data,
            'page' => $this,
        ]);
    }

    protected function autosaveWithinTransaction(callable $write): void
    {
        if (! method_exists($this, 'beginDatabaseTransaction')
            || ! method_exists($this, 'commitDatabaseTransaction')
            || ! method_exists($this, 'rollBackDatabaseTransaction')
        ) {
            $write();

            return;
        }

        try {
            $this->beginDatabaseTransaction();
            $write();
            $this->commitDatabaseTransaction();
        } catch (Halt $e) {
            $e->shouldRollbackDatabaseTransaction()
                ? $this->rollBackDatabaseTransaction()
                : $this->commitDatabaseTransaction();

            throw $e;
        } catch (\Throwable $e) {
            $this->rollBackDatabaseTransaction();

            throw $e;
        }
    }

    /**
     * A required field left blank would trip NOT NULL and sink the whole write.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function dropBlankRequiredAutosaveFields(array $data): array
    {
        AutosaveFieldTree::eachMatch(
            $data,
            $this->getAutosaveFields(),
            function (array &$data, array $fields, string $match): void {
                if ($this->anyAutosaveField($fields, 'isRequired') && blank(data_get($data, $match))) {
                    $this->forgetAutosavePath($data, $match);
                }
            },
        );

        return $data;
    }

    /**
     * A container maps to one column value: a partial write wipes the skipped field.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function dropIncompleteAutosaveContainers(array $data): array
    {
        foreach (array_keys($this->getAutosaveFields()) as $path) {
            if (! str_contains($path, '.')) {
                continue;
            }

            $top = AutosaveFieldTree::topLevelKey($path);

            if (array_key_exists($top, $data) && ! $this->autosavePathIsComplete($data, $path)) {
                unset($data[$top]);
            }
        }

        return $data;
    }
}
