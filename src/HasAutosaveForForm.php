<?php

namespace Lenorix\FilamentAutosave;

use Filament\Forms\Components\RichEditor;
use Filament\Resources\Events\RecordSaved;
use Filament\Resources\Events\RecordUpdated;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
    }

    #[Locked]
    public bool $autosaveHasDraft = false;

    #[Locked]
    public bool $autosaveCanUndo = false;

    #[Locked]
    public string $autosaveObservedHash = '';

    /** @var array<string, string> Hashes of the last acknowledged top-level fields. */
    #[Locked]
    public array $autosaveFieldHashes = [];

    public function mountHasAutosaveForForm(): void
    {
        if (! $this->initializeAutosaveState()) {
            return;
        }

        $this->autosaveDebounceMs = $this->getAutosaveDebounce();
        $this->autosaveHasDraft = $this->autosaveStore()->restoreDraft($this->getAutosaveCacheKey()) !== null;
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

        $data = $this->autosaveUploadsPersistenceData();

        foreach ($this->autosaveRelationshipFields() as $path => $fields) {
            foreach ($fields as $field) {
                $fieldPath = $this->autosaveFormRelativeFieldPath($field) ?? $path;

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

        foreach ($this->autosaveRelationshipFields() as $path => $fields) {
            foreach ($fields as $field) {
                $fieldPath = $this->autosaveFormRelativeFieldPath($field) ?? $path;

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

        $this->clearAutosaveFormUndo();
        $this->autosaveCanUndo = false;
        $this->putAutosaveFormUndo('values', AutosaveStore::normalizeScalars($previous));
        $this->putAutosaveFormUndo('relationships', $relationshipUndo);

        $this->callAutosaveHook('beforeSave');

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
        Event::dispatch(RecordUpdated::class, ['record' => $record, 'data' => $columns, 'page' => $this]);
        Event::dispatch(RecordSaved::class, ['record' => $record, 'data' => $columns, 'page' => $this]);

        $record->refresh();
        $this->putAutosaveFormUndo('expected', AutosaveStore::normalizeScalars($record->only(array_keys($columns))));
        $this->putAutosaveFormUndo('expected-relationships', $this->captureAutosaveFormRelationshipUndoForFields(
            $this->autosaveFormRelationshipFields(),
        ));
        $this->acknowledgeAutosaveUploads($uploads, $data);
        $this->autosaveCanUndo = $uploads === []
            && ! $this->autosaveRelationshipUploadsChanged()
            && ($previous !== [] || $relationshipUndo !== []);
        $this->clearAutosaveDraft();

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
            $this->clearAutosaveFormUndo();
            $this->autosaveCanUndo = false;
            $this->clearQueuedAutosaveNotification();

            throw $e;
        }
    }

    protected function sendAutosaveSavedNotification(): void
    {
        if (method_exists($this, 'getSavedNotification')) {
            $this->getSavedNotification()?->send();
        }
    }

    protected function autosaveFormWithinTransaction(callable $write): mixed
    {
        if (! method_exists($this, 'beginDatabaseTransaction')
            || ! method_exists($this, 'commitDatabaseTransaction')
            || ! method_exists($this, 'rollBackDatabaseTransaction')) {
            $record = $this->getAutosaveFormRecord();

            return $record?->exists ? DB::transaction($write) : $write();
        }

        try {
            $this->beginDatabaseTransaction();
            $result = $write();
            $this->commitDatabaseTransaction();

            return $result;
        } catch (Halt $exception) {
            $exception->shouldRollbackDatabaseTransaction()
                ? $this->rollBackDatabaseTransaction()
                : $this->commitDatabaseTransaction();

            throw $exception;
        } catch (\Throwable $exception) {
            $this->rollBackDatabaseTransaction();

            throw $exception;
        }
    }

    public function undoAutosave(): void
    {
        try {
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
            $expected = $this->autosaveFormUndo('expected');
            $expectedRelationships = $this->autosaveFormUndo('expected-relationships');
            $record = $this->getAutosaveFormRecord();

            if ($record === null
                || (($snapshot === null || $snapshot === [])
                    && ($relationshipSnapshot === null || $relationshipSnapshot === []))
                || $expected === null) {
                $this->autosaveCanUndo = false;
                $this->dispatchAutosaveIdle();

                return;
            }

            if ((method_exists($record, 'only') && AutosaveStore::normalizeScalars($record->only(array_keys($expected))) !== $expected)
                || ($expectedRelationships !== null && $this->autosaveFormRelationshipHasConflict($expectedRelationships))) {
                $this->clearAutosaveFormUndo();
                $this->autosaveCanUndo = false;
                $this->dispatch(AutosaveStatus::EVENT, status: AutosaveStatus::Conflict->value);

                return;
            }

            $this->autosaveFormWithinTransaction(function () use ($record, $snapshot, $relationshipSnapshot): void {
                $this->callAutosaveHook('beforeValidate');
                $this->callAutosaveHook('afterValidate');
                $this->callAutosaveHook('beforeSave');

                if ($snapshot !== []) {
                    $record->update($snapshot);
                }

                if ($relationshipSnapshot !== []) {
                    $this->restoreAutosaveFormRelationshipUndo($relationshipSnapshot);
                }

                $this->callAutosaveHook('afterSave');
                Event::dispatch(RecordUpdated::class, ['record' => $record, 'data' => $snapshot, 'page' => $this]);
                Event::dispatch(RecordSaved::class, ['record' => $record, 'data' => $snapshot, 'page' => $this]);
            });

            $record->refresh();
            $this->fillAutosaveFormFromRecord($record, $snapshot);
            $this->autosaveFieldHashes = $this->hashAutosaveFormFields(
                $this->prepareAutosavePayload($this->getAutosaveData()),
            );
            $this->autosaveSnapshotHash = $this->currentAutosaveSnapshotHash();
            $this->clearAutosaveFormUndo();
            $this->autosaveCanUndo = false;

            if (method_exists($this, 'rememberData')) {
                $this->rememberData();
            }

            if (method_exists($this, 'getSavedNotification')) {
                $this->getSavedNotification()?->send();
            }

            $this->dispatch(AutosaveStatus::EVENT, status: AutosaveStatus::Undone->value);
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
        Cache::forget($this->getAutosaveFormUndoKey('values'));
        Cache::forget($this->getAutosaveFormUndoKey('relationships'));
        Cache::forget($this->getAutosaveFormUndoKey('expected'));
        Cache::forget($this->getAutosaveFormUndoKey('expected-relationships'));
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
        $snapshot = [];

        foreach ($this->autosaveFormRelationshipFields() as $path => $fields) {
            if (! array_key_exists(AutosaveFieldTree::topLevelKey($path), $data)) {
                continue;
            }

            foreach ($fields as $index => $field) {
                $snapshotPath = count($fields) === 1
                    ? $path
                    : ($this->autosaveFormRelativeFieldPath($field) ?? $path.'.'.$index);
                $captured = $this->captureAutosaveFormRelationshipField($field);

                if ($captured !== null) {
                    $snapshot[$snapshotPath] = $captured;
                }
            }
        }

        return $snapshot;
    }

    /** @param array<string, array<int, object>> $fieldsByPath @return array<string, array<string, mixed>> */
    protected function captureAutosaveFormRelationshipUndoForFields(array $fieldsByPath): array
    {
        $snapshot = [];

        foreach ($fieldsByPath as $path => $fields) {
            foreach ($fields as $index => $field) {
                $snapshotPath = count($fields) === 1
                    ? $path
                    : ($this->autosaveFormRelativeFieldPath($field) ?? $path.'.'.$index);
                $captured = $this->captureAutosaveFormRelationshipField($field);

                if ($captured !== null) {
                    $snapshot[$snapshotPath] = $captured;
                }
            }
        }

        return $snapshot;
    }

    /** @return array<string, mixed>|null */
    protected function captureAutosaveFormRelationshipField(object $field): ?array
    {
        if (! method_exists($field, 'getRelationship')) {
            return null;
        }

        $relationship = $field->getRelationship();

        return match (true) {
            $relationship instanceof MorphTo => [
                'type' => 'morphTo',
                'attributes' => $this->captureAutosaveFormMorphTo($relationship),
            ],
            $relationship instanceof BelongsToMany => [
                'type' => 'belongsToMany',
                'rows' => $this->captureAutosaveFormBelongsToMany($relationship),
            ],
            $relationship instanceof HasOneOrManyThrough, $relationship instanceof HasOneOrMany => [
                'type' => $relationship instanceof HasOneOrManyThrough ? 'hasOneOrManyThrough' : 'hasOneOrMany',
                'rows' => $this->captureAutosaveFormHasMany($relationship),
            ],
            default => null,
        };
    }

    /** @return array<string, mixed> */
    protected function captureAutosaveFormMorphTo(MorphTo $relationship): array
    {
        $parent = $relationship->getParent();

        return AutosaveStore::normalizeScalars([
            $relationship->getMorphType() => $parent->getAttribute($relationship->getMorphType()),
            $relationship->getForeignKeyName() => $parent->getAttribute($relationship->getForeignKeyName()),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    protected function captureAutosaveFormBelongsToMany(BelongsToMany $relationship): array
    {
        $rows = [];

        foreach ($relationship->get() as $related) {
            $rows[] = [
                'key' => $related->getKey(),
                'pivot' => $related->pivot?->getAttributes() ?? [],
            ];
        }

        return AutosaveStore::normalizeScalars($rows);
    }

    /** @return array<int, array<string, mixed>> */
    protected function captureAutosaveFormHasMany(HasOneOrMany|HasOneOrManyThrough $relationship): array
    {
        return AutosaveStore::normalizeScalars($relationship->get()->map(function (Model $related): array {
            $attributes = $related->getAttributes();
            unset($attributes['laravel_through_key']);

            return ['attributes' => $attributes];
        })->all());
    }

    /** @param array<string, array<string, mixed>> $expected */
    protected function autosaveFormRelationshipHasConflict(array $expected): bool
    {
        $current = $this->captureAutosaveFormRelationshipUndoForFields($this->autosaveFormRelationshipFields());

        foreach ($expected as $path => $state) {
            if (($current[$path] ?? null) !== $state) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, array<string, mixed>> $snapshot */
    protected function restoreAutosaveFormRelationshipUndo(array $snapshot): void
    {
        $fieldsByPath = $this->autosaveFormRelationshipFields();

        foreach ($snapshot as $path => $state) {
            $field = $this->autosaveFormRelationshipFieldForPath($path, $fieldsByPath);

            if ($field === null || ! method_exists($field, 'getRelationship')) {
                continue;
            }

            $relationship = $field->getRelationship();

            match (true) {
                $relationship instanceof MorphTo => $this->restoreAutosaveFormMorphTo($relationship, $state['attributes'] ?? []),
                $relationship instanceof BelongsToMany => $this->restoreAutosaveFormBelongsToMany($relationship, $state['rows'] ?? []),
                $relationship instanceof HasOneOrManyThrough => $this->restoreAutosaveFormHasManyThrough($relationship, $state['rows'] ?? []),
                $relationship instanceof HasOneOrMany => $this->restoreAutosaveFormHasMany($relationship, $state['rows'] ?? []),
                default => null,
            };
        }
    }

    /** @param array<string, array<int, object>> $fieldsByPath */
    protected function autosaveFormRelationshipFieldForPath(string $path, array $fieldsByPath): ?object
    {
        foreach ($fieldsByPath as $pattern => $fields) {
            foreach ($fields as $field) {
                if (($this->autosaveFormRelativeFieldPath($field) ?? $pattern) === $path) {
                    return $field;
                }
            }
        }

        return $fieldsByPath[$path][0] ?? null;
    }

    protected function autosaveFormRelativeFieldPath(object $field): ?string
    {
        if (! method_exists($field, 'getStatePath') || ! filled($path = $field->getStatePath())) {
            return null;
        }

        return AutosaveFieldTree::relativePath((string) $path, $this->getAutosaveStatePath());
    }

    /** @param array<string, mixed> $attributes */
    protected function restoreAutosaveFormMorphTo(MorphTo $relationship, array $attributes): void
    {
        if ($attributes !== []) {
            $relationship->getParent()->forceFill($attributes)->save();
        }
    }

    /** @param array<int, array<string, mixed>> $rows */
    protected function restoreAutosaveFormBelongsToMany(BelongsToMany $relationship, array $rows): void
    {
        $ids = [];

        foreach ($rows as $row) {
            $ids[$row['key']] = $row['pivot'] ?? [];
        }

        $relationship->sync($ids);
    }

    /** @param array<int, array<string, mixed>> $rows */
    protected function restoreAutosaveFormHasManyThrough(HasOneOrManyThrough $relationship, array $rows): void
    {
        $related = $relationship->getRelated();
        $keyName = $related->getKeyName();
        $original = $this->autosaveFormRowsByKey($rows, $keyName);

        $this->deleteAutosaveFormRowsMissingFrom($relationship, $original);

        foreach ($original as $attributes) {
            $model = $related->newQuery()->whereKey($attributes[$keyName])->first()
                ?? $related->newInstance();
            $model->forceFill($attributes);
            $model->save();
        }
    }

    /** @param array<int, array<string, mixed>> $rows */
    protected function restoreAutosaveFormHasMany(HasOneOrMany $relationship, array $rows): void
    {
        $related = $relationship->getRelated();
        $keyName = $related->getKeyName();
        $original = $this->autosaveFormRowsByKey($rows, $keyName);

        $this->deleteAutosaveFormRowsMissingFrom($relationship, $original);

        foreach ($original as $attributes) {
            $model = $related->newQuery()->whereKey($attributes[$keyName])->first()
                ?? $related->newInstance();
            $model->forceFill($attributes);
            $relationship->save($model);
        }
    }

    /** @param array<int, array<string, mixed>> $rows @return array<string, array<string, mixed>> */
    protected function autosaveFormRowsByKey(array $rows, string $keyName): array
    {
        $original = [];

        foreach ($rows as $row) {
            $attributes = $row['attributes'] ?? [];
            $key = (string) ($attributes[$keyName] ?? '');

            if ($key !== '') {
                $original[$key] = $attributes;
            }
        }

        return $original;
    }

    /** @param object $relationship @param array<string, array<string, mixed>> $original */
    protected function deleteAutosaveFormRowsMissingFrom(object $relationship, array $original): void
    {
        foreach ($relationship->get() as $current) {
            if (! array_key_exists((string) $current->getKey(), $original)) {
                $current->delete();
            }
        }
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

    public function restoreDraft(): void
    {
        try {
            $this->authorizeAutosaveAccess();

            $draft = $this->autosaveStore()->restoreDraft($this->getAutosaveCacheKey());

            if ($draft === null) {
                $this->dispatchAutosaveIdle();

                return;
            }

            $this->fillAutosaveData($this->prepareAutosavePayload($draft));
            $this->autosaveSnapshotHash = $this->currentAutosaveSnapshotHash();
            $this->autosaveFieldHashes = $this->hashAutosaveFormFields(
                $this->prepareAutosavePayload($this->getAutosaveData()),
            );
            $this->autosaveHasDraft = false;

            $this->dispatch(AutosaveStatus::EVENT, status: AutosaveStatus::Restored->value);
        } catch (\Throwable $e) {
            $this->handleAutosaveFailure($e, 'restore');
        }
    }

    public function discardDraft(): void
    {
        $this->authorizeAutosaveAccess();

        $this->clearAutosaveDraft();
        $this->dispatchAutosaveIdle();
    }

    public function clearAutosaveDraft(): void
    {
        $this->autosaveStore()->clearDraft($this->getAutosaveCacheKey());
        $this->autosaveHasDraft = false;
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

    protected function getAutosaveCacheTtl(): int
    {
        return AutosavePlugin::resolve()->getCacheTtl();
    }
}
