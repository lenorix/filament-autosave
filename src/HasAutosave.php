<?php

namespace Lenorix\FilamentAutosave;

use Filament\Forms\Components\BaseFileUpload;
use Filament\Forms\Components\RichEditor;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Arr;
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

    /** Undo has been prepared but the surrounding transaction has not committed. */
    protected bool $autosaveUndoPrepared = false;

    /**
     * Livewire lifecycle; not an extension point.
     *
     * @internal
     */
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

    /**
     * Livewire lifecycle; not an extension point.
     *
     * @internal
     */
    public function mountHasAutosave(): void
    {
        if (! $this->initializeAutosaveState()) {
            return;
        }

        $this->autosaveDebounceMs = $this->getAutosaveDebounce();
        $this->autosavePollMs = $this->getAutosavePollInterval();

        $this->resetAutosaveHashes();

        if (($record = $this->autosaveSyncRecord()) !== null) {
            $this->rememberAutosaveSyncedAttributes($record);
        }
    }

    /**
     * Dirty fields of a payload. Dirtiness is judged on the value the form
     * holds (`$formValues`), not on what a mutator turned it into: the hashes
     * acknowledge form values, so comparing a transformed value against them
     * would report the field dirty on every cycle. A key the mutator added
     * has no form value and is always written.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $formValues
     * @return array<string, mixed>
     */
    protected function filterAutosavePayload(array $data, array $formValues = []): array
    {
        if (! $this->autosaveDirtyOnly() || $this->autosaveFieldHashes === null) {
            return $data;
        }

        return array_filter($data, fn ($value, $key): bool => ($this->autosaveFieldHashes[$key] ?? null) !== $this->hashAutosaveValue(
            array_key_exists($key, $formValues) ? $formValues[$key] : $value,
        ), ARRAY_FILTER_USE_BOTH);
    }

    protected function resetAutosaveHashes(): void
    {
        $data = $this->prepareAutosavePayload($this->getAutosaveData());
        $this->autosaveFieldHashes = $this->hashAutosaveFields($data);
        $this->autosaveSnapshotHash = $this->autosaveStore()->snapshotHash($data);
        $this->resetAutosaveUploadHashes();
        $this->resetAutosaveRelationshipHashes();
    }

    /** Post-save refresh of clean columns; see HasAutosaveBase::refreshAutosaveFieldsFromRecord(). */
    protected function refreshAutosaveUnchangedFields(): void
    {
        $this->autosaveRefreshState = [];

        if ($this->autosaveFieldHashes === null) {
            return;
        }

        $this->refreshAutosaveFieldsFromRecord($this->getRecord());
    }

    protected function autosaveSyncRecord(): ?object
    {
        return method_exists($this, 'getRecord') ? $this->getRecord() : null;
    }

    protected function autosaveCanRefillFromRecord(): bool
    {
        return $this->autosaveFieldHashes !== null && method_exists($this, 'refreshFormData');
    }

    protected function autosaveFieldIsClean(string $path, mixed $value): bool
    {
        return ($this->autosaveFieldHashes[$path] ?? null) === $this->hashAutosaveValue($value);
    }

    protected function acknowledgeAutosaveRefreshedField(string $path, mixed $value): void
    {
        $this->autosaveFieldHashes[$path] = $this->hashAutosaveValue($value);
    }

    /**
     * Filament's partial refresh applies casts and fill hooks like a normal
     * fill, but cannot carry an array attribute; those are filled whole.
     *
     * @param  array<int, string>  $paths
     */
    protected function refillAutosaveFieldsFromRecord(object $record, array $paths): void
    {
        $attributes = method_exists($record, 'attributesToArray') ? $record->attributesToArray() : [];
        $arrays = array_values(array_filter($paths, static fn (string $path): bool => is_array($attributes[$path] ?? null)));
        $scalars = array_values(array_diff($paths, $arrays));

        if ($scalars !== []) {
            $this->refreshFormData($scalars);
        }

        if ($arrays !== [] && ($form = $this->resolveAutosaveForm()) !== null) {
            $this->fillAutosavePathsPartially(
                $form,
                method_exists($this, 'mutateFormDataBeforeFill') ? $this->mutateFormDataBeforeFill($attributes) : $attributes,
                $arrays,
            );
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

    /**
     * Only acknowledge fields the persistence callback actually wrote.
     *
     * @param  array<string, mixed>  $written
     */
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

    /**
     * Background entry point: never throws, reports through the indicator.
     *
     * @param  array<string, string|array{base: string, ours: string}>  $mergePatches  Per mergeable field, a diff-match-patch patch of the browser's change (or the base it started from). See HasAutosaveMerge.
     *
     * @api
     */
    public function autosave(array $mergePatches = []): void
    {
        $this->acceptAutosaveMergePatches($mergePatches);

        $this->performAutosave(function (array $data): array|false {
            $data = $this->storeAutosavePendingUploads($data);
            $uploads = $this->consumePendingUploads($data);

            $this->callAutosaveHook('beforeSave');
            $formValues = $data;

            if (method_exists($this, 'mutateFormDataBeforeSave')) {
                $data = $this->mutateFormDataBeforeSave($data);
            }

            // Keep the full state available to validation and page mutators;
            // only the final record write is reduced to dirty fields.
            $data = $this->filterAutosavePayload($data, $formValues);

            $relationships = $this->resolvePendingAutosaveRelationships($data);

            $eligible = $data;
            $data = array_diff_key($this->dropIncompleteAutosaveContainers(
                $this->dropBlankRequiredAutosaveFields($data)
            ), $this->autosaveBlockedUploadColumns);
            $this->markAutosavePendingFields(array_keys(array_diff_key($eligible, $data)));
            $this->markAutosavePendingFields(array_keys($this->autosaveBlockedUploadColumns));

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
        $this->autosaveWrittenPaths = array_keys($data + $uploads + $relationships);

        // Merged columns come back with the value actually stored; a column
        // left contended is dropped so nothing acknowledges the user's text.
        $data = $this->writeAutosave($data, $uploads, $relationships);
        $this->autosaveWrittenFieldHashes = array_diff_key($this->autosaveWrittenFieldHashes, $this->autosaveContendedValues);
        $this->markAutosavePendingFields($this->autosaveContendedPaths());

        $this->getRecord()->refresh();
        $this->refreshAutosaveUnchangedFields();
        $this->refreshAutosaveMergedFields($this->getRecord());
        $this->autosaveWrittenFieldHashes = array_replace($this->autosaveWrittenFieldHashes, array_intersect_key(
            $this->autosaveFieldHashes ?? [], array_diff_key($this->autosaveMergedValues, $this->autosaveContendedValues),
        ));
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
        $externalFields = $this->autosaveExternalUndoFields($uploads, $relationships);
        $externalUndo = $this->autosaveExternalUndoSnapshots($externalFields);
        $this->storeUndoExternalSnapshot($externalUndo);
        $unsafeExternalUndo = $this->autosaveExternalUndoHasUnsupported($externalFields)
            || array_diff_key($externalFields, $externalUndo) !== []
            || $this->autosaveRelationshipUndoTruncated
            || $this->autosaveRelationshipsHaveFilePersistence($relationships);
        $this->autosaveCanUndo = ! $unsafeExternalUndo && ($columnUndo || $relationUndo || $externalUndo !== []);

        if ($this->autosaveCanUndo) {
            $this->storeUndoExpectedExternalSnapshot($externalFields);
        }
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
        $forget = [];

        // Read every pending component's state before forgetting anything:
        // a parent repeater's path holds the whole subtree, so removing it
        // first would leave nested relationship repeaters (`a.*.b`) with no
        // state to resolve and they would be dropped, along with the
        // user's changes, while the cycle still reported "saved".
        foreach ($relationships as $path => $fields) {
            $resolved = false;

            foreach ($fields as $field) {
                $fieldPath = $this->autosaveRelativeFieldPath($field) ?? $path;

                // Only a concrete instance path (`a.record-1.b`) names this
                // component's own state; data_get() on a wildcard pattern
                // would hand it a collapsed array of every row.
                if (str_contains($fieldPath, '*')) {
                    continue;
                }

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
                    $forget[] = $fieldPath;
                }

                $resolved = true;
            }

            if (! $resolved) {
                unset($relationships[$path]);
                $this->markAutosavePendingFields([AutosaveFieldTree::topLevelKey($path)]);
            }
        }

        foreach ($forget as $fieldPath) {
            $this->forgetAutosavePath($data, $fieldPath);
        }

        $this->autosavePendingRelationships = $relationships;

        return $relationships;
    }

    /**
     * Re-read the pending components from the live form tree right before
     * they are saved.
     *
     * A page hook that runs Filament's own save path -- typically a
     * `handleRecordUpdate()` calling `$this->form->getState()`, as translatable
     * Edit-page concerns do -- already persists the relationships, rebuilds
     * every repeater's child schemas and re-keys the rows it created to
     * `record-{id}`. The component instances captured before that hook keep
     * a record cache from before those rows existed, so saving through them
     * would create the same rows a second time. Instances taken from the
     * current tree see the re-keyed state and update instead. Without such a
     * hook the fresh instances resolve to the same state, so nothing changes.
     *
     * @param  array<string, array<object>>  $relationships
     * @param  array<string, mixed>  $pending
     * @param  array<string, array<string, mixed>>  $fingerprints
     * @return array<string, array<object>>
     */
    protected function refreshAutosavePendingRelationships(array $relationships, array $pending = [], array $fingerprints = []): array
    {
        $this->autosaveFieldsCache = null;
        $live = $this->autosaveRelationshipFields();
        $current = $fingerprints !== [] ? $this->captureAutosaveRelationshipUndoFields($relationships) : [];

        foreach (array_keys($relationships) as $path) {
            if (isset($live[$path])) {
                $relationships[$path] = $live[$path];
            }

            // A hook that refilled the form WITHOUT writing this relationship
            // (e.g. `$this->form->fill($this->form->getState(false))`) has
            // re-hydrated its repeaters from the database, discarding the
            // user's pending rows and edits. If the rows are untouched since
            // before the hook, put the resolved state back so the pass below
            // still writes it. If the hook did write, the live state is the
            // re-keyed truth and must be kept, or rows would be created twice.
            if ($fingerprints !== []) {
                foreach ($relationships[$path] as $field) {
                    $fieldPath = $this->autosaveRelativeFieldPath($field) ?? $path;
                    $before = $fingerprints[$fieldPath] ?? $fingerprints[$path] ?? null;
                    $after = $current[$fieldPath] ?? $current[$path] ?? null;

                    // Restore only what we can prove untouched: a real snapshot
                    // that is identical after the hook, for a component whose
                    // parent row still exists in the live state. A nested
                    // component under a row the hook re-keyed (new-row ->
                    // record-N) must be skipped: writing to its old state path
                    // would resurrect an empty parent row.
                    if ($before === null || $before !== $after
                        || ! array_key_exists($fieldPath, $pending)
                        || ! method_exists($field, 'rawState')
                        || ! $this->autosaveParentRowExists($fieldPath)) {
                        continue;
                    }

                    $field->rawState($pending[$fieldPath]);
                }
            }

            // Filament fills a repeater's existing-record cache while building
            // its child schemas, which can predate rows the hook just created.
            // saveToRelationship() treats any state key missing from that
            // cache as a row to create, so drop it and let it re-read the
            // relationship as it stands now.
            foreach ($relationships[$path] as $field) {
                if (method_exists($field, 'clearCachedExistingRecords')) {
                    $field->clearCachedExistingRecords();
                }
            }
        }

        $this->autosavePendingRelationships = $relationships;

        return $relationships;
    }

    /** Whether the row that owns a nested field path is still present in the live form state. */
    protected function autosaveParentRowExists(string $fieldPath): bool
    {
        if (! str_contains($fieldPath, '.')) {
            return true;
        }

        $parent = substr($fieldPath, 0, strrpos($fieldPath, '.'));
        $raw = $this->resolveAutosaveForm()?->getRawState();

        return is_array($raw) && Arr::has($raw, $parent);
    }

    /**
     * Resolved raw state of every pending relationship component, keyed by
     * its concrete field path, taken before any page hook can touch the form.
     *
     * @param  array<string, array<object>>  $relationships
     * @return array<string, mixed>
     */
    protected function captureAutosavePendingRelationshipState(array $relationships): array
    {
        $state = [];

        foreach ($relationships as $path => $fields) {
            foreach ($fields as $field) {
                if (method_exists($field, 'getRawState')) {
                    $state[$this->autosaveRelativeFieldPath($field) ?? $path] = $field->getRawState();
                }
            }
        }

        return $state;
    }

    /**
     * @param  array<string, array<object>>  $relationships
     * @return array<string, array<object>>
     */
    protected function autosaveRelationshipsInnermostFirst(array $relationships): array
    {
        uksort($relationships, fn (string $a, string $b): int => substr_count($b, '.') <=> substr_count($a, '.'));

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
            $pending = $this->captureAutosavePendingRelationshipState($relationships);
            $fingerprints = $data !== [] && $relationships !== []
                ? $this->captureAutosaveRelationshipUndoFields($relationships)
                : [];
            $merge = $this->extractAutosaveMergeColumns($data);

            if ($data !== []) {
                $this->handleRecordUpdate($this->getRecord(), $data);
            }

            if ($merge !== []) {
                $result = $this->writeAutosaveMergeColumns($this->getRecord(), $merge);
                $data = array_replace($data, $result['written']);
                $this->rememberAutosaveMergeUndo($result['previous']);
            }

            $this->persistAutosaveUploadRelationships($uploads);

            // Innermost first, the order Filament's own Schema::saveRelationships()
            // uses. Repeater::saveToRelationship() only recurses into a row's
            // children when it creates that row, so an existing row's nested
            // repeater has to save itself; a nested repeater in a row that
            // does not exist yet bails on its missing record and is then
            // created by the parent's own recursion, never twice.
            $relationships = $this->refreshAutosavePendingRelationships($relationships, $pending, $fingerprints);

            foreach ($this->autosaveRelationshipsInnermostFirst($relationships) as $fields) {
                foreach ($fields as $field) {
                    $field->saveRelationships();
                }
            }

            $this->callAutosaveHook('afterSave');
            $this->dispatchAutosaveRecordEvents($this->getRecord(), $data);

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
        $state = $this->data ?? [];
        $current = $this->prepareAutosavePayload($this->getAutosaveData());
        $covered = $written;
        foreach ($current as $key => $value) {
            if (($this->autosaveFieldHashes[$key] ?? null) === $this->hashAutosaveValue($value)) {
                $covered[$key] = $value;
            }
        }
        $this->includeStableAutosaveUploads($covered);
        $this->includeWrittenAutosaveRelationships($covered);

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

    /**
     * Relationships persisted this cycle are as complete as any other written
     * field, so Filament's unsaved-changes alert can re-baseline over them too.
     * An unresolved relationship never reaches `$autosavePendingRelationships`
     * (see `resolvePendingAutosaveRelationships()`), so only what was actually
     * saved is included here.
     *
     * @param  array<string, mixed>  $covered
     */
    protected function includeWrittenAutosaveRelationships(array &$covered): void
    {
        foreach ($this->autosavePendingRelationships as $path => $fields) {
            foreach ($fields as $field) {
                $fieldPath = $this->autosaveRelativeFieldPath($field) ?? $path;

                if (str_contains($fieldPath, '*')) {
                    continue;
                }

                data_set($covered, $fieldPath, $field->getRawState());
            }
        }
    }

    /**
     * Restore the previous autosave if its target is unchanged; conflicts are reported, not overwritten.
     *
     * @api
     */
    public function undoAutosave(): void
    {
        try {
            $this->authorizeAutosaveAccess();

            // The locked flag prevents undo from an older page load.
            $snapshot = $this->autosaveUndoCached(AutosaveUndo::VALUES);
            $relationshipSnapshot = $this->autosaveUndoCached(AutosaveUndo::RELATIONSHIPS);
            $externalSnapshot = $this->autosaveUndoCached(AutosaveUndo::EXTERNAL);

            if ((! is_array($snapshot) || empty($snapshot))
                && (! is_array($relationshipSnapshot) || empty($relationshipSnapshot))
                && (! is_array($externalSnapshot) || empty($externalSnapshot))) {
                $this->autosaveCanUndo = false;
                $this->dispatchAutosaveIdle();

                return;
            }

            $expected = $this->autosaveUndoCached(AutosaveUndo::EXPECTED);
            $expectedRelationships = $this->autosaveUndoCached(AutosaveUndo::EXPECTED_RELATIONSHIPS);
            $expectedExternal = $this->autosaveUndoCached(AutosaveUndo::EXPECTED_EXTERNAL);

            if ($this->undoHasConflict($expected, $expectedRelationships)
                || ! $this->autosaveExternalUndoMatches(
                    $expectedExternal ?? [],
                    $this->autosaveExternalUndoFields(),
                )) {
                $this->resetAutosaveUndo();
                $this->dispatchAutosaveConflict();

                return;
            }

            $this->autosaveWithinTransaction(function () use ($snapshot, $relationshipSnapshot, $externalSnapshot): void {
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

                if (is_array($externalSnapshot) && $externalSnapshot !== []) {
                    $this->restoreAutosaveExternalUndo(
                        $externalSnapshot,
                        $this->autosaveExternalUndoFields(),
                    );
                }

                $this->callAutosaveHook('afterSave');
                $this->dispatchAutosaveRecordEvents($this->getRecord(), is_array($snapshot) ? $snapshot : []);
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

            $this->dispatchAutosaveUndone();
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

        return $this->autosaveUndo()->put(AutosaveUndo::VALUES, $previous);
    }

    /**
     * A merged column's "before" value is the one it was finally written
     * over, which a retry may have re-read; a contended column was not
     * written, so Undo must not touch it either.
     *
     * @param  array<string, mixed>  $previous
     */
    protected function rememberAutosaveMergeUndo(array $previous): void
    {
        $undo = $this->autosaveUndo();
        $values = array_diff_key($undo->get(AutosaveUndo::VALUES) ?? [], $this->autosaveContendedValues);

        foreach ($previous as $path => $value) {
            $values[$path] = $this->normalizeUndoSnapshot([$path => $value])[$path] ?? null;
        }

        $undo->replace(AutosaveUndo::VALUES, $values);
    }

    /**
     * Store the values written by this autosave for optimistic Undo checks.
     *
     * @param  array<int, string>  $fieldKeys
     */
    protected function storeUndoExpectedSnapshot(array $fieldKeys): void
    {
        $record = $this->getRecord();

        if ($fieldKeys === [] || ! $record || ! method_exists($record, 'only')) {
            return;
        }

        $this->autosaveUndo()->put(AutosaveUndo::EXPECTED, $this->normalizeUndoSnapshot($record->only($fieldKeys)));
    }

    /** @param array<string, mixed> $expected */
    protected function undoMatchesExpectedState(array $expected): bool
    {
        $record = $this->getRecord();

        if (! $record || ! method_exists($record, 'only')) {
            return true;
        }

        return AutosaveUndo::columnsMatch($expected, $this->normalizeUndoSnapshot($record->only(array_keys($expected))));
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
        return ! ($expected === null || $this->undoMatchesExpectedState($expected))
            || ! ($expectedRelationships === null || $this->undoMatchesExpectedRelationships($expectedRelationships));
    }

    /** @param array<string, array<object>> $relationships */
    protected function storeUndoExpectedRelationshipSnapshot(array $relationships): void
    {
        if ($relationships === []) {
            return;
        }

        $this->autosaveUndo()->put(AutosaveUndo::EXPECTED_RELATIONSHIPS, $this->captureAutosaveRelationshipUndo($relationships));
    }

    /** @param array<string, array<string, mixed>> $expected */
    protected function undoMatchesExpectedRelationships(array $expected): bool
    {
        return AutosaveUndo::relationshipsMatch(
            $expected,
            $this->captureAutosaveRelationshipUndo($this->autosaveRelationshipFields()),
        );
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
        return $this->captureAutosaveRelationshipUndoFields($relationships);
    }

    /**
     * RichEditor providers and uploads inside relationship rows can delete or
     * create files outside the DB transaction.
     *
     * @param  array<string, array<object>>  $relationships
     */
    protected function autosaveRelationshipsHaveFilePersistence(array $relationships): bool
    {
        foreach ($relationships as $fields) {
            foreach ($fields as $field) {
                if ($this->autosaveExternalUndoManager()->adapterFor($field) !== null) {
                    continue;
                }

                if ($field instanceof RichEditor
                    && (! method_exists($field, 'getFileAttachmentProvider') || $field->getFileAttachmentProvider() !== null)) {
                    return true;
                }
            }
        }

        foreach ($this->autosavePendingUploads as $path => $field) {
            if ($this->autosaveUploadInRelationship($path)
                && $this->autosaveExternalUndoManager()->adapterFor($field) === null) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<string, array<string, mixed>>  $snapshot */
    protected function storeUndoRelationshipSnapshot(array $snapshot): bool
    {
        return $this->autosaveUndo()->put(AutosaveUndo::RELATIONSHIPS, $snapshot);
    }

    /** @param array<string, array<string, mixed>> $snapshot */
    protected function storeUndoExternalSnapshot(array $snapshot): bool
    {
        return $this->autosaveUndo()->put(AutosaveUndo::EXTERNAL, $snapshot);
    }

    /** @param array<string, object> $fields */
    protected function storeUndoExpectedExternalSnapshot(array $fields): void
    {
        $this->autosaveUndo()->put(AutosaveUndo::EXPECTED_EXTERNAL, $this->autosaveExternalUndoManager()->snapshot($fields));
    }

    protected function clearUndoSnapshots(): void
    {
        $this->autosaveUndo()->clear();
    }

    /** Wipe the Undo target; the underlying state can no longer be restored. */
    protected function resetAutosaveUndo(): void
    {
        $this->clearUndoSnapshots();
        $this->autosaveCanUndo = false;
        $this->autosaveUndoPrepared = false;
    }

    protected function getUndoCacheKey(string $suffix = ''): string
    {
        return $this->autosaveStore()->undoCacheKey(static::class, $this->getRecord()?->getKey(), $this->autosaveUndoInstanceId())
            .($suffix ? ":{$suffix}" : '');
    }

    /**
     * Read an undo snapshot only when this page load still owns the feature.
     *
     * @return array<string, mixed>|null
     */
    protected function autosaveUndoCached(string $part): ?array
    {
        return $this->autosaveCanUndo ? $this->autosaveUndo()->get($part) : null;
    }

    /** The Undo target for this page, record and live instance. */
    protected function autosaveUndo(): AutosaveUndo
    {
        return new AutosaveUndo($this->getUndoCacheKey(), $this->getUndoTtlMinutes(), bareValuesKey: true);
    }

    /**
     * Hook called after a successful Edit-page save.
     *
     * @api
     */
    protected function afterAutosave(object $record): void {}

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
            $this->clearQueuedAutosaveNotification();

            // A Halt that kept the transaction committed the write: its
            // hashes and Undo snapshot are valid, only the report is quiet.
            if ($this->autosaveHaltCommittedWrite()) {
                throw $e;
            }

            $this->autosaveFieldHashes = $fieldHashes;
            $this->autosaveSnapshotHash = $snapshotHash;

            if ($this->autosaveUndoPrepared) {
                $this->resetAutosaveUndo();
            }

            throw $e;
        }
    }

    protected function autosaveWithinTransaction(callable $write): mixed
    {
        return $this->autosaveWithinDatabaseTransaction($write);
    }

    protected function autosaveEventRecord(): ?object
    {
        return method_exists($this, 'getRecord') ? $this->getRecord() : null;
    }
}
