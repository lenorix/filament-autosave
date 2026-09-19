<?php

namespace Lenorix\FilamentAutosave;

use Filament\Forms\Components\RichEditor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Livewire\Attributes\Locked;

/**
 * Autosave support for Livewire components that expose a Filament form.
 *
 * Forms without an existing record are stored as drafts. A schema bound to an
 * existing record is persisted with the standard record lifecycle and gets a
 * one-step Undo target. Components with a custom action lifecycle can still
 * override persistAutosaveForm().
 */
trait HasAutosaveForForm
{
    use HasAutosaveBase;
    use HasAutosaveDraft;
    use HasAutosaveUploads {
        HasAutosaveUploads::prepareAutosavePersistence insteadof HasAutosaveBase;
        HasAutosaveUploads::prepareAutosavePersistence as prepareAutosaveUploadsPersistence;
        HasAutosaveBase::prepareAutosavePersistence as prepareAutosaveBasePersistence;
        HasAutosaveUploads::autosavePersistenceData insteadof HasAutosaveBase;
        HasAutosaveUploads::autosavePersistenceData as autosaveUploadsPersistenceData;
        HasAutosaveBase::autosavePersistenceData as autosaveBasePersistenceData;
        HasAutosaveUploads::hasPendingAutosavePersistence insteadof HasAutosaveBase;
        HasAutosaveUploads::hasPendingAutosavePersistence as hasPendingAutosaveUploadsPersistence;
        HasAutosaveBase::hasPendingAutosavePersistence as hasPendingAutosaveBasePersistence;
        HasAutosaveUploads::discardAutosaveStoredUploads insteadof HasAutosaveBase;
        HasAutosaveUploads::commitAutosaveStoredUploads insteadof HasAutosaveBase;
        HasAutosaveBase::getAutosaveData as autosaveBaseData;
        HasAutosaveBase::autosaveWithoutDatabaseTransaction as autosaveBaseWithoutDatabaseTransaction;
    }

    #[Locked]
    public bool $autosaveCanUndo = false;

    #[Locked]
    public string $autosaveObservedHash = '';

    /** @var array<string, string> Hashes of the last acknowledged top-level fields. */
    #[Locked]
    public array $autosaveFieldHashes = [];

    /**
     * Call from mount(); Livewire lifecycle, not an extension point.
     *
     * @internal
     */
    public function mountHasAutosaveForForm(): void
    {
        if (! $this->initializeAutosaveState()) {
            return;
        }

        $this->autosaveDebounceMs = $this->getAutosaveDebounce();
        $this->autosaveHasDraft = $this->autosaveDraftAvailable();
        $this->autosaveSnapshotHash = $this->currentAutosaveSnapshotHash();
        $this->autosaveObservedHash = $this->autosaveSnapshotHash;
        $this->autosaveFieldHashes = $this->hashAutosaveFields($this->prepareAutosavePayload($this->getAutosaveData()));
        $this->resetAutosaveUploadHashes();

        $record = $this->getAutosaveFormRecord();

        if ($record instanceof Model && $record->exists) {
            $this->autosavePollMs = $this->getAutosavePollInterval();
            $this->rememberAutosaveSyncedAttributes($record);
        }
    }

    /**
     * Keep the indicator on the active action/modal schema after mounting it.
     *
     * @internal
     */
    public function dehydrateHasAutosaveForForm(): void
    {
        if (! $this->isAutosaveEnabled()) {
            return;
        }

        $this->autosaveDataPath = $this->getAutosaveStatePath();

        // Mirror dehydrateHasAutosave(): the snapshot hash strips temporary
        // uploads, so fold each upload field's own hash in or the browser
        // watcher never sees an upload-only server-side change.
        $files = [];

        foreach ($this->autosaveUploadFields() as $path => $field) {
            if (! $this->autosavePathExcluded($path)) {
                $files[$path] = $this->autosaveUploadHash($field);
            }
        }

        $this->autosaveObservedHash = $this->autosaveStore()->snapshotHash([
            'state' => $this->currentAutosaveSnapshotHash(),
            'files' => $files,
        ]);
    }

    /**
     * Background entry point: never throws, reports through the indicator.
     *
     * @api
     */
    public function autosave(array $mergePatches = []): void
    {
        $this->assertAutosaveFormContext();
        $this->acceptAutosaveMergePatches($mergePatches);
        $this->performAutosave(fn (array $data): bool|array => $this->persistAutosaveForm($data));
    }

    /** Uploads are only actionable when this form is bound to a record. */
    protected function prepareAutosavePersistence(): void
    {
        if (! (($record = $this->getAutosaveFormRecord()) instanceof Model) || ! $record->exists) {
            $this->autosavePendingUploads = [];
            $this->autosaveBlockedUploadColumns = [];

            return;
        }

        $this->prepareAutosaveUploadsPersistence();
    }

    /** @return array<string, mixed> */
    protected function autosavePersistenceData(): array
    {
        if (! (($record = $this->getAutosaveFormRecord()) instanceof Model) || ! $record->exists) {
            return $this->autosaveBasePersistenceData();
        }

        return $this->mergeAutosaveRelationshipState($this->autosaveUploadsPersistenceData());
    }

    /**
     * Record-backed generic forms persist relationships through the whole
     * schema, so every relationship component can own nested uploads.
     *
     * @return array<int, string>
     */
    protected function autosaveUploadRelationshipPatterns(): array
    {
        if (! (($record = $this->getAutosaveFormRecord()) instanceof Model) || ! $record->exists) {
            return [];
        }

        return array_keys($this->autosaveRelationshipFields());
    }

    protected function hasPendingAutosavePersistence(): bool
    {
        if (! (($record = $this->getAutosaveFormRecord()) instanceof Model) || ! $record->exists) {
            return $this->hasPendingAutosaveBasePersistence();
        }

        return $this->hasPendingAutosaveUploadsPersistence();
    }

    /** Capture provider-managed media around the generic form lifecycle too. */
    protected function getAutosaveData(): array
    {
        $this->captureAutosaveExternalMediaBaseline();
        $data = $this->autosaveBaseData();
        $this->captureAutosaveExternalMediaAfter();

        return $this->mergeAutosaveRelationshipState($data);
    }

    /**
     * Fold relationship component state into the payload being persisted.
     *
     * @return array<string, mixed>
     */
    protected function mergeAutosaveRelationshipState(array $data): array
    {
        foreach ($this->autosaveRelationshipFields() as $path => $fields) {
            foreach ($fields as $field) {
                $fieldPath = $this->autosaveRelativeFieldPath($field) ?? $path;

                if (str_contains($fieldPath, '*')) {
                    continue;
                }

                // A RichEditor is a column: the form holds its document, the
                // column takes its state cast (HTML or JSON).
                if ($field instanceof RichEditor) {
                    data_set($data, $fieldPath, $field->getState());
                } elseif (method_exists($field, 'getRawState')) {
                    data_set($data, $fieldPath, $field->getRawState());
                }
            }
        }

        return $data;
    }

    /**
     * Return relationship fields with their concrete instances. This mirrors
     * the Edit-page relationship map so generic forms can undo relation writes.
     *
     * @return array<string, array<int, object>>
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

                $relationship = method_exists($field, 'getRelationship')
                    ? $field->getRelationship()
                    : null;

                if ($relationship !== null || $field instanceof RichEditor) {
                    $relationships[$path][] = $field;
                }
            }
        }

        return $relationships;
    }

    /**
     * Persist the eligible state for this component.
     *
     * Existing-record schemas are written automatically. Override this method
     * when the component owns a custom action lifecycle or side effects.
     *
     * @param  array<string, mixed>  $data
     *
     * @api
     */
    protected function persistAutosaveForm(array $data): bool|array
    {
        $record = $this->getAutosaveFormRecord();

        if ($record instanceof Model && $record->exists) {
            $data = $this->storeAutosavePendingUploads($data);
            // Filament order: beforeSave runs before the mutator, with the
            // complete state still available on the component.
            $this->callAutosaveHook('beforeSave');
        }

        $formValues = $this->prepareAutosavePayload($data);

        // Filament applies this mutator immediately before persistence. Keep
        // null, empty strings and empty arrays: they represent deliberate
        // deletions and must reach the model.
        if (method_exists($this, 'mutateFormDataBeforeSave')) {
            $data = $this->mutateFormDataBeforeSave($data);
        }

        $prepared = $this->prepareAutosavePayload($data);
        $payload = $this->filterAutosaveFormPayload($prepared, $formValues);

        if ($record instanceof Model && $record->exists) {
            $payload = $this->keepAutosaveUploadRelationshipOwners($payload, $prepared);
            $this->filterAutosavePendingUploadsForPayload($payload);
            $this->markAutosavePendingFields(array_keys($this->autosaveBlockedUploadColumns));
        }

        if ($payload === []) {
            // A dirty-only request can be a no-op after an earlier draft was
            // written. Keep that draft available for restore instead of
            // deleting it merely because this request has no new fields.
            if (! $this->autosaveDirtyOnly()
                || $this->autosaveStore()->restoreDraft($this->getAutosaveCacheKey()) === null) {
                $this->clearAutosaveDraft();
            }

            return false;
        }

        if ($record instanceof Model && $record->exists) {
            return $this->persistAutosaveFormRecord($record, $payload);
        }

        $draft = $payload;

        if ($this->autosaveDirtyOnly()) {
            $draft = array_replace(
                $this->autosaveStore()->restoreDraft($this->getAutosaveCacheKey()) ?? [],
                $payload,
            );
        }

        $this->autosaveStore()->storeDraft(
            $this->getAutosaveCacheKey(),
            $draft,
            $this->getAutosaveCacheTtl(),
        );

        $this->autosaveHasDraft = true;
        $this->acknowledgeAutosaveFormFields(array_keys($payload));
        $this->autosaveSnapshotHash = $this->currentAutosaveSnapshotHash();

        return true;
    }

    /**
     * Acknowledge the given top-level fields as saved, hashed from the form
     * as the user sees it — never from the persisted payload. A mutator that
     * stores a transformed value (a slug from a title) would otherwise leave
     * the field "dirty" forever: rewritten on every cycle, last-write-wins,
     * and never refreshed from another editor's change.
     *
     * @param  array<int, string>  $paths
     */
    protected function acknowledgeAutosaveFormFields(array $paths): void
    {
        $this->autosaveFieldsCache = null;
        $live = $this->prepareAutosavePayload($this->getAutosaveData());

        $this->autosaveFieldHashes = array_replace(
            $this->autosaveFieldHashes,
            $this->hashAutosaveFields(array_intersect_key($live, array_flip($paths))),
        );
    }

    /**
     * Persist an action/modal form when its schema is bound to an existing
     * record. Create actions still remain drafts until the action is submitted.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function persistAutosaveFormRecord(Model $record, array $data): array
    {
        $uploads = $this->autosavePendingUploads;
        $columns = method_exists($record, 'getAttributes')
            ? array_intersect_key($data, array_flip(array_keys($record->getAttributes())))
            : $data;
        $previous = method_exists($record, 'only') ? $record->only(array_keys($columns)) : [];
        $relationshipUndo = $this->captureAutosaveFormRelationshipUndo($data);
        $externalFields = $this->autosaveExternalUndoFields($uploads, $this->autosaveFormDirtyRelationshipFields($data));
        $externalUndo = $this->autosaveExternalUndoSnapshots($externalFields);

        $this->resetAutosaveFormUndo();
        $this->putAutosaveFormUndo('values', AutosaveStore::normalizeScalars($previous));
        $this->putAutosaveFormUndo('relationships', $relationshipUndo);
        $this->putAutosaveFormUndo('external', $externalUndo);

        $merge = $this->extractAutosaveMergeColumns($columns);

        if (method_exists($this, 'handleRecordUpdate')) {
            $this->handleRecordUpdate($record, $columns);
        } else {
            $record->update($columns);
        }

        if ($merge !== []) {
            // Merged columns carry the value actually stored; a column left
            // contended is dropped everywhere so the user's text stays dirty
            // and Undo never touches it.
            $result = $this->writeAutosaveMergeColumns($record, $merge);
            $contended = $this->autosaveContendedValues;
            $columns = array_diff_key(array_replace($columns, $result['written']), $contended);
            $data = array_diff_key(array_replace($data, $result['written']), $contended);
            $previous = array_diff_key(array_replace($previous, $result['previous']), $contended);
            $this->autosaveUndo()->replace(AutosaveUndo::VALUES, AutosaveStore::normalizeScalars($previous));
            $this->markAutosavePendingFields($this->autosaveContendedPaths());
        }

        $this->saveAutosaveFormRelationships($data, $uploads);

        $this->callAutosaveHook('afterSave');
        $this->dispatchAutosaveRecordEvents($record, $columns);

        $record->refresh();
        $this->putAutosaveFormUndo('expected', AutosaveStore::normalizeScalars($record->only(array_keys($columns))));
        $this->putAutosaveFormUndo('expected-relationships', $this->captureAutosaveRelationshipUndoFields(
            $this->autosaveFormDirtyRelationshipFields($data),
        ));
        $this->putAutosaveFormUndo('expected-external', $this->autosaveExternalUndoManager()->snapshot($externalFields));
        $this->acknowledgeAutosaveUploads($uploads, $data);
        $this->autosaveCanUndo = ! $this->autosaveExternalUndoHasUnsupported($externalFields)
            && array_diff_key($externalFields, $externalUndo) === []
            && ! $this->autosaveRelationshipUndoTruncated
            && ($previous !== [] || $relationshipUndo !== [] || $externalUndo !== []);
        $this->clearAutosaveDraft();

        // Before the hashes below acknowledge this write, so "clean" still
        // means "unchanged since the last acknowledged state".
        $this->refreshAutosaveUnchangedFields($record);
        $this->refreshAutosaveMergedFields($record);

        // A relationship callback may have persisted state that is not a
        // model column. Acknowledge every top-level value supplied to the
        // form, otherwise the same relation is considered dirty forever.
        $this->acknowledgeAutosaveFormFields(array_keys($data));
        $this->autosaveSnapshotHash = $this->currentAutosaveSnapshotHash();
        $this->queueAutosaveSavedNotification();

        if (method_exists($this, 'afterAutosave')) {
            $this->afterAutosave($record);
        }

        return $columns;
    }

    /**
     * `RecordUpdated`/`RecordSaved` require a real `Filament\Resources\Pages\Page`
     * to construct. Relation managers, actions, and other generic Livewire
     * components using this trait are not one, so `HasAutosaveBase`'s
     * dispatch falls back to Filament's own class-name-plus-array
     * convention -- which throws inside any listener type-hinted against the
     * event class, an error the autosave failure handler swallows, silently
     * rolling back the whole write. These events are Edit-page only; use
     * `afterAutosave()` or the package's own hooks here instead.
     */
    protected function dispatchAutosaveRecordEvents(object $record, array $data): void {}

    /** Post-save refresh of clean columns; see HasAutosaveBase::refreshAutosaveFieldsFromRecord(). */
    protected function refreshAutosaveUnchangedFields(Model $record): void
    {
        $this->autosaveRefreshState = [];

        if (! $record->exists) {
            return;
        }

        $this->refreshAutosaveFieldsFromRecord($record);
    }

    protected function autosaveSyncRecord(): ?object
    {
        return $this->getAutosaveFormRecord();
    }

    protected function autosaveCanRefillFromRecord(): bool
    {
        $form = $this->resolveAutosaveForm();

        return $form !== null && method_exists($form, 'fillPartially');
    }

    protected function autosaveFieldIsClean(string $path, mixed $value): bool
    {
        return $this->autosaveFieldHashMatches($path, $value);
    }

    /**
     * Compare a field against its acknowledged hash, tolerating the sha256
     * hashes (64 hex chars) generic forms stored before hashing was unified
     * on xxh128 (32). A tab opened before that deploy carries them in its
     * Livewire state; treating them as "dirty" would write every field with
     * the tab's stale values on the first autosave and overwrite whatever
     * another editor changed since. The baseline is rebuilt from the record
     * instead, so only fields that really differ from the database are written.
     */
    protected function autosaveFieldHashMatches(string $path, mixed $value): bool
    {
        $stored = $this->autosaveFieldHashes[$path] ?? null;
        $current = $this->hashAutosaveValue($value);

        if (is_string($stored) && strlen($stored) === 64 && ctype_xdigit($stored)) {
            $this->autosaveFieldHashes[$path] = $this->autosaveLegacyBaselineHash($path, $current);
        }

        return ($this->autosaveFieldHashes[$path] ?? null) === $current;
    }

    /**
     * Baseline for a field whose stored hash predates xxh128. The legacy hash
     * says nothing about the value the tab loaded, but the per-attribute
     * fingerprint the poll keeps from mount does: if the record's attribute is
     * unchanged since then, any difference with the form is the user's own
     * edit and the record value is the baseline (so that edit is written);
     * if someone else changed it meanwhile, the form value becomes the
     * baseline (so the tab's stale value never overwrites theirs).
     */
    protected function autosaveLegacyBaselineHash(string $path, string $currentHash): string
    {
        $record = $this->getAutosaveFormRecord();

        if (! $record instanceof Model || ! $record->exists || ! array_key_exists($path, $record->getAttributes())) {
            return $currentHash;
        }

        $seenAtMount = $this->autosaveSyncedAttributeHashes[$path] ?? null;
        $now = $this->autosaveStore()->snapshotHash(['v' => $record->getAttributes()[$path]]);

        if ($seenAtMount === null || $seenAtMount !== $now) {
            return $currentHash;
        }

        return $this->hashAutosaveValue(AutosaveStore::normalizeScalars([$path => $record->getAttribute($path)])[$path]);
    }

    protected function acknowledgeAutosaveRefreshedField(string $path, mixed $value): void
    {
        $this->autosaveFieldHashes[$path] = $this->hashAutosaveValue($value);
    }

    /** Generic components have no refreshFormData(); fill the schema partially. */
    protected function refillAutosaveFieldsFromRecord(object $record, array $paths): void
    {
        $attributes = $record->attributesToArray();

        $this->fillAutosavePathsPartially(
            $this->resolveAutosaveForm(),
            method_exists($this, 'mutateFormDataBeforeFill') ? $this->mutateFormDataBeforeFill($attributes) : $attributes,
            $paths,
        );
    }

    protected function getAutosaveFormRecord(): ?Model
    {
        $form = $this->resolveAutosaveForm();

        if ($form !== null && method_exists($form, 'getRecord')) {
            try {
                $record = $form->getRecord();

                if ($record instanceof Model) {
                    return $record;
                }
            } catch (\Throwable) {
                // Action and modal schemas may not have a record while they
                // are being mounted or detached.
            }
        }

        if (method_exists($this, 'getRecord')) {
            try {
                $record = $this->getRecord();

                return $record instanceof Model ? $record : null;
            } catch (\Throwable) {
                // Standalone forms do not necessarily own a record.
            }
        }

        return null;
    }

    protected function runAutosaveCycle(callable $cycle): mixed
    {
        $fieldHashes = $this->autosaveFieldHashes;
        $snapshotHash = $this->autosaveSnapshotHash;
        $this->clearQueuedAutosaveNotification();

        try {
            $result = $this->autosaveFormWithinTransaction($cycle);
            $this->flushAutosaveSavedNotification();

            return $result;
        } catch (\Throwable $e) {
            $this->autosaveFieldHashes = $fieldHashes;
            $this->autosaveSnapshotHash = $snapshotHash;
            // A failed write or commit invalidates the snapshot prepared for
            // this request. Do not leave a stale generic Undo target behind.
            $this->resetAutosaveFormUndo();
            $this->clearQueuedAutosaveNotification();

            throw $e;
        }
    }

    protected function autosaveFormWithinTransaction(callable $write): mixed
    {
        return $this->autosaveWithinDatabaseTransaction($write);
    }

    /** A recordless draft only touches the cache, so it needs no transaction. */
    protected function autosaveWithoutDatabaseTransaction(callable $write): mixed
    {
        if (! $this->getAutosaveFormRecord()?->exists) {
            return $write();
        }

        return $this->autosaveBaseWithoutDatabaseTransaction($write);
    }

    /**
     * Restore the previous autosave if its target is unchanged; conflicts are reported, not overwritten.
     *
     * @api
     */
    public function undoAutosave(): void
    {
        try {
            $this->assertAutosaveFormContext();
            $this->authorizeAutosaveAccess();

            // Upload and external-media writes are deliberately outside the
            // one-step database Undo contract. A direct call must not restore
            // only the column while leaving the newly stored file behind.
            if (! $this->autosaveCanUndo) {
                $this->dispatchAutosaveIdle();

                return;
            }

            // Empty parts are not stored, so a missing part reads as "nothing".
            $snapshot = $this->autosaveFormUndo(AutosaveUndo::VALUES) ?? [];
            $relationshipSnapshot = $this->autosaveFormUndo(AutosaveUndo::RELATIONSHIPS) ?? [];
            $externalSnapshot = $this->autosaveFormUndo(AutosaveUndo::EXTERNAL) ?? [];
            $expected = $this->autosaveFormUndo(AutosaveUndo::EXPECTED) ?? [];
            $expectedRelationships = $this->autosaveFormUndo(AutosaveUndo::EXPECTED_RELATIONSHIPS);
            $expectedExternal = $this->autosaveFormUndo(AutosaveUndo::EXPECTED_EXTERNAL);
            $record = $this->getAutosaveFormRecord();

            if ($record === null || ! $this->autosaveUndo()->hasSnapshot()) {
                $this->autosaveCanUndo = false;
                $this->dispatchAutosaveIdle();

                return;
            }

            $currentColumns = method_exists($record, 'only')
                ? AutosaveStore::normalizeScalars($record->only(array_keys($expected)))
                : $expected;

            if (! AutosaveUndo::columnsMatch($expected, $currentColumns)
                || $this->autosaveFormRelationshipHasConflict($expectedRelationships)) {
                $this->resetAutosaveFormUndo();
                $this->dispatchAutosaveConflict();

                return;
            }

            // Scoped to the paths captured in $expectedExternal, i.e. exactly
            // what this write touched: matches() requires the two key sets to
            // agree exactly, and an untouched external field could otherwise
            // report a conflict for activity this Undo has nothing to do with.
            $externalFields = array_intersect_key($this->autosaveExternalUndoFields(), $expectedExternal ?? []);

            if (! $this->autosaveExternalUndoMatches($expectedExternal ?? [], $externalFields)) {
                $this->resetAutosaveFormUndo();
                $this->dispatchAutosaveConflict();

                return;
            }

            $this->autosaveFormWithinTransaction(function () use ($record, $snapshot, $relationshipSnapshot, $externalSnapshot, $externalFields): void {
                $this->callAutosaveHook('beforeValidate');
                $this->callAutosaveHook('afterValidate');
                $this->callAutosaveHook('beforeSave');

                if ($snapshot !== []) {
                    $record->update($snapshot);
                }

                if ($relationshipSnapshot !== []) {
                    $this->restoreAutosaveRelationshipUndo($relationshipSnapshot);
                }

                if ($externalSnapshot !== []) {
                    $this->restoreAutosaveExternalUndo($externalSnapshot, $externalFields);
                }

                $this->callAutosaveHook('afterSave');
                $this->dispatchAutosaveRecordEvents($record, $snapshot);
            });

            $record->refresh();
            $this->fillAutosaveFormFromRecord($record, $snapshot);
            $this->rehashAutosaveFields();
            $this->autosaveSnapshotHash = $this->currentAutosaveSnapshotHash();
            $this->resetAutosaveFormUndo();

            if (method_exists($this, 'rememberData')) {
                $this->rememberData();
            }

            if (method_exists($this, 'getSavedNotification')) {
                $this->getSavedNotification()?->send();
            }

            $this->dispatchAutosaveUndone();
        } catch (\Throwable $e) {
            $this->handleAutosaveFailure($e, 'undo');
        }
    }

    /** The Undo target for this form's context, record and live instance. */
    protected function autosaveUndo(): AutosaveUndo
    {
        $record = $this->getAutosaveFormRecord();

        return new AutosaveUndo(
            $this->autosaveStore()->undoCacheKey(
                static::class.':'.$this->getAutosaveFormContext(),
                $record?->getKey() ?? 'default',
                $this->autosaveUndoInstanceId(),
            ),
            $this->getUndoTtlMinutes(),
        );
    }

    protected function getAutosaveFormUndoKey(string $part): string
    {
        return $this->autosaveUndo()->key($part);
    }

    protected function putAutosaveFormUndo(string $part, array $value): void
    {
        $this->autosaveUndo()->put($part, $value);
    }

    /** @return array<string, mixed>|null */
    protected function autosaveFormUndo(string $part): ?array
    {
        return $this->autosaveUndo()->get($part);
    }

    protected function clearAutosaveFormUndo(): void
    {
        $this->autosaveUndo()->clear();
    }

    /** Wipe the generic Undo target; the underlying state can no longer be restored. */
    protected function resetAutosaveFormUndo(): void
    {
        $this->clearAutosaveFormUndo();
        $this->autosaveCanUndo = false;
    }

    /** @return array<string, array<int, object>> */
    protected function autosaveFormRelationshipFields(): array
    {
        $relationships = [];

        foreach ($this->autosaveRelationshipFields() as $path => $fields) {
            foreach ($fields as $field) {
                if ($field instanceof RichEditor || ! method_exists($field, 'getRelationship')) {
                    continue;
                }

                $relationship = $field->getRelationship();

                // A normal BelongsTo is represented by a model foreign-key
                // column. MorphTo needs its own snapshot because it carries a
                // type and key pair.
                if ($relationship instanceof BelongsTo && ! $relationship instanceof MorphTo) {
                    continue;
                }

                if ($relationship instanceof MorphTo
                    || $relationship instanceof BelongsToMany
                    || $relationship instanceof HasOneOrMany
                    || $relationship instanceof HasOneOrManyThrough) {
                    $relationships[$path][] = $field;
                }
            }
        }

        return $relationships;
    }

    /** @param array<string, mixed> $data @return array<string, array<string, mixed>> */
    protected function captureAutosaveFormRelationshipUndo(array $data): array
    {
        return $this->captureAutosaveRelationshipUndoFields($this->autosaveFormDirtyRelationshipFields($data));
    }

    /**
     * Relationship fields whose top-level key is present in a payload.
     *
     * Used both to snapshot the "before" state at write time and, later, the
     * expected "after" state for Undo's conflict check: the two must agree on
     * the same subset, or a concurrent change to a relationship this autosave
     * never touched would look like a conflict and needlessly cancel Undo.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<int, object>>
     */
    protected function autosaveFormDirtyRelationshipFields(array $data): array
    {
        $fieldsByPath = $this->autosaveFormRelationshipFields();

        foreach (array_keys($fieldsByPath) as $path) {
            if (! array_key_exists(AutosaveFieldTree::topLevelKey($path), $data)) {
                unset($fieldsByPath[$path]);
            }
        }

        return $fieldsByPath;
    }

    /** @param array<string, array<string, mixed>>|null $expected */
    protected function autosaveFormRelationshipHasConflict(?array $expected): bool
    {
        return ! AutosaveUndo::relationshipsMatch(
            $expected,
            $this->captureAutosaveRelationshipUndoFields($this->autosaveFormRelationshipFields()),
        );
    }

    protected function fillAutosaveFormFromRecord(Model $record, array $fallback): void
    {
        $form = $this->resolveAutosaveForm();

        if ($form !== null && method_exists($form, 'fill') && method_exists($record, 'attributesToArray')) {
            $form->fill($record->attributesToArray());

            return;
        }

        $this->fillAutosaveData($fallback);
    }

    /** Rebuild the acknowledged field hashes after a draft has been filled in. */
    protected function autosaveDraftRestored(): void
    {
        $this->rehashAutosaveFields();
    }

    /** Rebuild the field hashes so local edits are compared against disk state. */
    protected function rehashAutosaveFields(): void
    {
        $this->autosaveFieldHashes = $this->hashAutosaveFields(
            $this->prepareAutosavePayload($this->getAutosaveData()),
        );
    }

    /**
     * Return a stable identifier for this form instance.
     *
     * Relation managers should include the owner and action/table forms should
     * include their record or action name to avoid sharing drafts.
     *
     * @api
     */
    protected function getAutosaveFormContext(): string
    {
        $context = [];

        if (method_exists($this, 'getMountedAction')) {
            try {
                $action = $this->getMountedAction();

                if ($action !== null && method_exists($action, 'getName')) {
                    $context[] = 'action:'.$action->getName();

                    if (method_exists($action, 'getRecord')) {
                        try {
                            $actionRecord = $action->getRecord(withDefault: false);

                            if ($actionRecord instanceof Model && $actionRecord->getKey() !== null) {
                                $context[] = 'action-record:'.get_class($actionRecord).':'.$actionRecord->getKey();
                            }
                        } catch (\Throwable) {
                            // A mounted action may not have resolved its row yet.
                        }
                    }
                }
            } catch (\Throwable) {
                // There is no active modal during the initial render.
            }
        }

        foreach (['getOwnerRecord' => 'owner:', 'getRecord' => 'record:'] as $method => $prefix) {
            $record = null;

            if (method_exists($this, $method)) {
                try {
                    $record = $this->{$method}();
                } catch (\Throwable) {
                    // Standalone forms may not have an owner or record yet.
                }
            } elseif ($method === 'getOwnerRecord' && isset($this->ownerRecord)) {
                $record = $this->ownerRecord;
            } elseif ($method === 'getRecord' && isset($this->record)) {
                $record = $this->record;
            }

            if ($record instanceof Model && $record->getKey() !== null) {
                $context[] = $prefix.get_class($record).':'.$record->getKey();
            }
        }

        if ($context === []) {
            $context[] = 'default';
        }

        return implode('|', $context);
    }

    /**
     * Require callers to disambiguate reusable generic form instances when
     * the application opts into strict context isolation.
     */
    protected function assertAutosaveFormContext(): void
    {
        if (! (bool) config('filament-autosave.require_form_context', false)) {
            return;
        }

        $context = $this->getAutosaveFormContext();

        if ($context === 'default' || $context === '') {
            throw new \LogicException(
                'HasAutosaveForForm requires an explicit context. Override getAutosaveFormContext() with a stable owner, record, or action identifier.',
            );
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
     */
    protected function filterAutosaveFormPayload(array $data, array $formValues = []): array
    {
        if (! $this->autosaveDirtyOnly() || $this->autosaveFieldHashes === []) {
            return $data;
        }

        return array_filter(
            $data,
            fn (mixed $value, string|int $key): bool => ! $this->autosaveFieldHashMatches(
                (string) $key,
                array_key_exists($key, $formValues) ? $formValues[$key] : $value,
            ),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Temporary uploads are stripped before hashing, so a relationship whose
     * only change is a new nested file looks clean to dirty-only filtering.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $prepared
     * @return array<string, mixed>
     */
    protected function keepAutosaveUploadRelationshipOwners(array $payload, array $prepared): array
    {
        foreach (array_keys($this->autosavePendingUploads) as $path) {
            $top = AutosaveFieldTree::topLevelKey($path);

            if ($this->autosaveUploadInRelationship($path)
                && ! array_key_exists($top, $payload)
                && array_key_exists($top, $prepared)) {
                $payload[$top] = $prepared[$top];
            }
        }

        return $payload;
    }

    /**
     * Persist the relationship components this cycle touched — those whose
     * top-level field is in the dirty payload, plus the owners of pending
     * uploads — each the way `Schema::saveRelationships()` would (before
     * children, its child schemas, then itself). Never the whole schema: an
     * untouched repeater would be rewritten from this tab's stale copy over
     * what another editor saved since, and Undo, which snapshots only
     * touched relationships, could not bring it back.
     *
     * Without dirty-only every field is in the payload, so the whole schema
     * is saved as before.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, object>  $uploads
     */
    protected function saveAutosaveFormRelationships(array $data, array $uploads): void
    {
        $form = $this->resolveAutosaveForm();

        if ($form === null || ! method_exists($form, 'saveRelationships')) {
            return;
        }

        if (! $this->autosaveDirtyOnly()) {
            $form->saveRelationships();

            return;
        }

        $touched = array_flip(array_map(AutosaveFieldTree::topLevelKey(...), [...array_keys($data), ...array_keys($uploads)]));

        if ($touched !== []) {
            $this->saveAutosaveTouchedRelationships($form, $touched);
        }
    }

    /**
     * Walk a schema like `Schema::saveRelationships()`, descending through
     * layout components and saving only the fields whose top-level state key
     * was touched — with their whole subtree, so a row's nested upload or
     * repeater is persisted with its parent.
     *
     * @param  array<string, true>  $touched
     */
    protected function saveAutosaveTouchedRelationships(object $schema, array $touched): void
    {
        if (! method_exists($schema, 'getComponents')) {
            return;
        }

        foreach ($schema->getComponents(withActions: false, withHidden: true) as $component) {
            $path = $this->autosaveRelativeFieldPath($component);

            if ($path === null || $path === '') {
                foreach ($component->getChildSchemas(withHidden: true) as $child) {
                    $this->saveAutosaveTouchedRelationships($child, $touched);
                }

                continue;
            }

            if (! isset($touched[AutosaveFieldTree::topLevelKey($path)])) {
                continue;
            }

            $component->saveRelationshipsBeforeChildren();
            $whenDisabled = $component->shouldSaveRelationshipsWhenDisabled();

            foreach ($component->getChildSchemas(withHidden: $component->shouldSaveRelationshipsWhenHidden()) as $child) {
                if (! $whenDisabled && $child->isDisabled()) {
                    continue;
                }

                $child->saveRelationships();
            }

            $component->saveRelationships();
        }
    }

    protected function getAutosaveCacheKey(): string
    {
        return $this->autosaveStore()->cacheKey(static::class.':'.$this->getAutosaveFormContext());
    }

    /** A draft's unsaved model instance is not a record anyone can act on. */
    protected function autosaveEventRecord(): ?object
    {
        $record = $this->getAutosaveFormRecord();

        return $record?->exists ? $record : null;
    }
}
