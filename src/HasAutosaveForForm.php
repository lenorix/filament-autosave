<?php

namespace Lenorix\FilamentAutosave;

use Filament\Forms\Components\RichEditor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

    /** @var array<string, mixed> Clean columns re-read from the record for the status event. */
    protected array $autosaveRefreshState = [];

    public function mountHasAutosaveForForm(): void
    {
        if (! $this->initializeAutosaveState()) {
            return;
        }

        $this->autosaveDebounceMs = $this->getAutosaveDebounce();
        $this->autosaveHasDraft = $this->autosaveDraftAvailable();
        $this->autosaveSnapshotHash = $this->currentAutosaveSnapshotHash();
        $this->autosaveObservedHash = $this->autosaveSnapshotHash;
        $this->autosaveFieldHashes = $this->hashAutosaveFormFields($this->prepareAutosavePayload($this->getAutosaveData()));
        $this->resetAutosaveUploadHashes();
    }

    /** Keep the indicator on the active action/modal schema after mounting it. */
    public function dehydrateHasAutosaveForForm(): void
    {
        if (! $this->isAutosaveEnabled()) {
            return;
        }

        $this->autosaveDataPath = $this->getAutosaveStatePath();
        $this->autosaveObservedHash = $this->currentAutosaveSnapshotHash();
    }

    public function autosave(): void
    {
        $this->assertAutosaveFormContext();
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

                if (method_exists($field, 'getRawState')) {
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

        // Filament applies this mutator immediately before persistence. Keep
        // null, empty strings and empty arrays: they represent deliberate
        // deletions and must reach the model.
        if (method_exists($this, 'mutateFormDataBeforeSave')) {
            $data = $this->mutateFormDataBeforeSave($data);
        }

        $prepared = $this->prepareAutosavePayload($data);
        $payload = $this->filterAutosaveFormPayload($prepared);

        if ($record instanceof Model && $record->exists) {
            $payload = $this->keepAutosaveUploadRelationshipOwners($payload, $prepared);
            $this->filterAutosavePendingUploadsForPayload($payload);
            $this->markAutosavePendingFields(array_keys($this->autosaveBlockedUploadColumns));
        }

        if ($payload === []) {
            // A dirty-only request can be a no-op after an earlier draft was
            // written. Keep that draft available for restore instead of
            // deleting it merely because this request has no new fields.
            if (! (bool) config('filament-autosave.dirty_only', false)
                || $this->autosaveStore()->restoreDraft($this->getAutosaveCacheKey()) === null) {
                $this->clearAutosaveDraft();
            }

            return false;
        }

        if ($record instanceof Model && $record->exists) {
            return $this->persistAutosaveFormRecord($record, $payload);
        }

        $draft = $payload;

        if ((bool) config('filament-autosave.dirty_only', false)) {
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
        $this->autosaveFieldHashes = array_replace($this->autosaveFieldHashes, $this->hashAutosaveFormFields($payload));
        $this->autosaveSnapshotHash = $this->currentAutosaveSnapshotHash();

        return true;
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

        if (method_exists($this, 'handleRecordUpdate')) {
            $this->handleRecordUpdate($record, $columns);
        } else {
            $record->update($columns);
        }

        if (($form = $this->resolveAutosaveForm()) !== null
            && method_exists($form, 'saveRelationships')
            && ($this->shouldSaveAutosaveFormRelationships($data) || $uploads !== [])) {
            $form->saveRelationships();
        }

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

        // A relationship callback may have persisted state that is not a
        // model column. Acknowledge every top-level value supplied to the
        // form, otherwise the same relation is considered dirty forever.
        $this->autosaveFieldHashes = array_replace($this->autosaveFieldHashes, $this->hashAutosaveFormFields($data));
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
     * Re-read clean, model-backed columns from the record after a write so
     * another editor's changes to fields this user is not touching show up
     * in the same response. Dirty fields, relationships, uploads, and
     * excluded paths are never refreshed. Mirrors the Edit-page behaviour;
     * generic components have no refreshFormData(), so this fills the schema
     * partially itself.
     */
    protected function refreshAutosaveUnchangedFields(Model $record): void
    {
        $this->autosaveRefreshState = [];

        if (! (bool) config('filament-autosave.dirty_only', false)
            || ! (bool) config('filament-autosave.refresh_unchanged_fields', true)
            || ! $record->exists
            || ($form = $this->resolveAutosaveForm()) === null
            || ! method_exists($form, 'fillPartially')) {
            return;
        }

        $current = $this->prepareAutosavePayload($this->getAutosaveData());
        $skip = [];

        foreach ($current as $path => $value) {
            if (($this->autosaveFieldHashes[(string) $path] ?? null) !== $this->hashAutosaveFormValue($value)) {
                $skip[AutosaveFieldTree::topLevelKey((string) $path)] = true;
            }
        }

        foreach (array_keys($this->autosaveRelationshipFields()) as $path) {
            $skip[AutosaveFieldTree::topLevelKey($path)] = true;
        }

        foreach (array_keys($this->autosaveUploadFields()) as $path) {
            $skip[AutosaveFieldTree::topLevelKey($path)] = true;
        }

        $attributes = $record->attributesToArray();
        $paths = [];

        foreach (array_keys($current) as $path) {
            $top = AutosaveFieldTree::topLevelKey((string) $path);

            if (! isset($skip[$top]) && ! $this->autosavePathExcluded($top) && array_key_exists($top, $attributes)) {
                $paths[$top] = true;
            }
        }

        if ($paths === []) {
            return;
        }

        try {
            $form->fillPartially(
                method_exists($this, 'mutateFormDataBeforeFill') ? $this->mutateFormDataBeforeFill($attributes) : $attributes,
                array_keys($paths),
            );
        } catch (\Throwable $e) {
            Log::warning('Autosave unchanged-field refresh failed', ['exception' => $e::class]);

            return;
        }

        $refreshed = $this->prepareAutosavePayload($this->getAutosaveData());

        foreach (array_keys($paths) as $path) {
            if (array_key_exists($path, $refreshed)) {
                $this->autosaveRefreshState[$path] = $refreshed[$path];
                $this->autosaveFieldHashes[$path] = $this->hashAutosaveFormValue($refreshed[$path]);
            }
        }
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

            $snapshot = $this->autosaveFormUndo('values');
            $relationshipSnapshot = $this->autosaveFormUndo('relationships');
            $externalSnapshot = $this->autosaveFormUndo('external');
            $expected = $this->autosaveFormUndo('expected');
            $expectedRelationships = $this->autosaveFormUndo('expected-relationships');
            $expectedExternal = $this->autosaveFormUndo('expected-external');
            $record = $this->getAutosaveFormRecord();

            if ($record === null
                || (($snapshot === null || $snapshot === [])
                    && ($relationshipSnapshot === null || $relationshipSnapshot === [])
                    && ($externalSnapshot === null || $externalSnapshot === []))
                || $expected === null) {
                $this->autosaveCanUndo = false;
                $this->dispatchAutosaveIdle();

                return;
            }

            if ((method_exists($record, 'only') && AutosaveStore::normalizeScalars($record->only(array_keys($expected))) !== $expected)
                || ($expectedRelationships !== null && $this->autosaveFormRelationshipHasConflict($expectedRelationships))) {
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
            $this->rehashAutosaveFormFields();
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

    protected function getAutosaveFormUndoKey(string $part): string
    {
        $record = $this->getAutosaveFormRecord();
        $recordKey = $record?->getKey() ?? 'default';

        return $this->autosaveStore()->undoCacheKey(static::class.':'.$this->getAutosaveFormContext(), $recordKey).':'.$part;
    }

    protected function putAutosaveFormUndo(string $part, array $value): void
    {
        Cache::put($this->getAutosaveFormUndoKey($part), $value, now()->addMinutes(AutosavePlugin::resolve()->getUndoCacheTtl()));
    }

    /** @return array<string, mixed>|null */
    protected function autosaveFormUndo(string $part): ?array
    {
        $value = Cache::get($this->getAutosaveFormUndoKey($part));

        return is_array($value) ? $value : null;
    }

    protected function clearAutosaveFormUndo(): void
    {
        foreach ($this->autosaveFormUndoParts() as $part) {
            Cache::forget($this->getAutosaveFormUndoKey($part));
        }
    }

    /** The six snapshot parts that make up a generic Undo target. */
    protected function autosaveFormUndoParts(): array
    {
        return ['values', 'relationships', 'expected', 'expected-relationships', 'external', 'expected-external'];
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

    /** @param array<string, array<string, mixed>> $expected */
    protected function autosaveFormRelationshipHasConflict(array $expected): bool
    {
        $current = $this->captureAutosaveRelationshipUndoFields($this->autosaveFormRelationshipFields());

        foreach ($expected as $path => $state) {
            if (($current[$path] ?? null) !== $state) {
                return true;
            }
        }

        return false;
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
        $this->rehashAutosaveFormFields();
    }

    /** Rebuild the field hashes so local edits are compared against disk state. */
    protected function rehashAutosaveFormFields(): void
    {
        $this->autosaveFieldHashes = $this->hashAutosaveFormFields(
            $this->prepareAutosavePayload($this->getAutosaveData()),
        );
    }

    /**
     * Return a stable identifier for this form instance.
     *
     * Relation managers should include the owner and action/table forms should
     * include their record or action name to avoid sharing drafts.
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

    /** @param array<string, mixed> $data */
    protected function filterAutosaveFormPayload(array $data): array
    {
        if (! (bool) config('filament-autosave.dirty_only', false) || $this->autosaveFieldHashes === []) {
            return $data;
        }

        return array_filter(
            $data,
            fn (mixed $value, string|int $key): bool => ($this->autosaveFieldHashes[(string) $key] ?? null)
                !== $this->hashAutosaveFormValue($value),
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

    /** @param array<string, mixed> $data @return array<string, string> */
    protected function hashAutosaveFormFields(array $data): array
    {
        $hashes = [];

        foreach ($data as $key => $value) {
            $hashes[(string) $key] = $this->hashAutosaveFormValue($value);
        }

        return $hashes;
    }

    protected function hashAutosaveFormValue(mixed $value): string
    {
        return hash('sha256', serialize($value));
    }

    /** @param array<string, mixed> $data */
    protected function shouldSaveAutosaveFormRelationships(array $data): bool
    {
        if (! (bool) config('filament-autosave.dirty_only', false)) {
            return true;
        }

        $fields = $this->getAutosaveFields();

        if ($fields === []) {
            return true;
        }

        foreach ($fields as $path => $fieldSet) {
            foreach ($fieldSet as $field) {
                $relationship = method_exists($field, 'getRelationship')
                    ? $field->getRelationship()
                    : null;

                if ($relationship !== null && array_key_exists(AutosaveFieldTree::topLevelKey($path), $data)) {
                    return true;
                }
            }
        }

        return false;
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
