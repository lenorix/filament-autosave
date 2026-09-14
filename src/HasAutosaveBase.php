<?php

namespace Lenorix\FilamentAutosave;

use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

trait HasAutosaveBase
{
    // Expose the server decision to Alpine and lock it from the client.
    #[Locked]
    public bool $autosaveEnabled = true;

    #[Locked]
    public string $autosaveSnapshotHash = '';

    #[Locked]
    public int $autosaveDebounceMs = 0;

    /** Livewire path watched by the indicator; defaults to the Filament form path. */
    #[Locked]
    public string $autosaveDataPath = 'data';

    /** Validation failures remain visible while valid sibling fields save. */
    #[Locked]
    public array $autosaveValidationErrors = [];

    /** @var array<int, string> Fields omitted from the current write. */
    #[Locked]
    public array $autosavePendingFields = [];

    /** @var array<int, string> Native error keys added by autosave. */
    #[Locked]
    public array $autosaveValidationKeys = [];

    protected bool $isAutosaving = false;

    protected bool $autosaveCycleActive = false;

    /** Set by flushAutosave(): fail loudly instead of reporting through the indicator. */
    protected bool $autosaveThrows = false;

    protected bool $autosaveCycleWrote = false;

    /** Notifications are sent only after the surrounding write commits. */
    protected bool $autosaveNotificationPending = false;

    /** @var array<string, array<object>>|null Cached field map for this request. */
    protected ?array $autosaveFieldsCache = null;

    protected AutosaveStore $autosaveStore;

    protected function autosaveStore(): AutosaveStore
    {
        return $this->autosaveStore ??= app(AutosaveStore::class);
    }

    protected function authorizeAutosaveAccess(): void
    {
        if (method_exists($this, 'authorizeAccess')) {
            $this->authorizeAccess();
        }
    }

    protected function dispatchAutosaveIdle(): void
    {
        $this->dispatch(AutosaveStatus::EVENT, status: AutosaveStatus::Idle->value);
    }

    protected function shouldAutosave(): bool
    {
        return true;
    }

    /** Return milliseconds; null uses plugin or config. */
    protected function autosaveDebounce(): ?int
    {
        return null;
    }

    /** @return array<string> */
    protected function autosaveExcept(): array
    {
        return [];
    }

    /**
     * Select the values that should be sent to the persistence callback.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function filterAutosavePayload(array $data): array
    {
        return $data;
    }

    /** Return the snapshot hash to keep after a successful write. */
    protected function autosaveSuccessSnapshotHash(array $written): string
    {
        return $this->currentAutosaveSnapshotHash();
    }

    public function isAutosaveEnabled(): bool
    {
        return $this->autosaveEnabled && $this->shouldAutosave();
    }

    public function getAutosaveDebounce(): int
    {
        $pageDebounce = $this->autosaveDebounce();

        if ($pageDebounce !== null && $pageDebounce > 0) {
            return $pageDebounce;
        }

        return AutosavePlugin::resolve()->getDebounce();
    }

    /** @return array<string> */
    public function getAutosaveExcept(): array
    {
        return array_values(array_unique([
            ...config('filament-autosave.except', []),
            ...(AutosavePlugin::tryGet()?->getExcept() ?? []),
            ...$this->autosaveExcept(),
        ]));
    }

    protected function autosavePathExcluded(string $path): bool
    {
        return in_array(AutosaveFieldTree::topLevelKey($path), $this->getAutosaveExcept(), true);
    }

    protected function initializeAutosaveState(): bool
    {
        $this->autosaveEnabled = $this->shouldAutosave();
        $this->autosaveDataPath = $this->getAutosaveStatePath();

        return $this->autosaveEnabled;
    }

    /** Override when a page keeps its form state outside the default `data` path. */
    protected function getAutosaveStatePath(): string
    {
        $form = $this->resolveAutosaveForm();

        if ($form !== null && method_exists($form, 'getStatePath') && filled($path = $form->getStatePath())) {
            return (string) $path;
        }

        return 'data';
    }

    /**
     * Run one autosave cycle synchronously and let failures propagate.
     *
     * This is the entry point for explicit actions that want the same
     * dirty-only write, refresh, and Undo behaviour as the background
     * autosave. Unlike `autosave()`, validation errors abort the cycle before
     * anything is written and surface as a `ValidationException`, and
     * exceptions thrown by hooks, custom rules, or persistence reach the
     * caller. Returns whether anything was written.
     *
     * @throws ValidationException
     * @throws \LogicException when called from inside a running autosave cycle
     */
    public function flushAutosave(): bool
    {
        if ($this->isAutosaving || $this->autosaveCycleActive) {
            throw new \LogicException('flushAutosave() cannot be called from inside an autosave cycle.');
        }

        $this->autosaveThrows = true;
        $this->autosaveCycleWrote = false;

        try {
            $this->autosave();
        } finally {
            $this->autosaveThrows = false;
        }

        return $this->autosaveCycleWrote;
    }

    protected function performAutosave(callable $persist): void
    {
        if (! $this->isAutosaveEnabled() || $this->isAutosaving) {
            return;
        }

        if (! $this->autosaveCycleActive) {
            $this->autosaveCycleActive = true;
            $this->autosaveCycleWrote = false;

            try {
                $this->runAutosaveCycle(fn () => $this->performAutosave($persist));
            } catch (Halt $e) {
                $this->discardAutosaveStoredUploads();
                $this->dispatchAutosaveIdle();

                if ($this->autosaveThrows) {
                    throw $e;
                }
            } catch (ValidationException $e) {
                $this->discardAutosaveStoredUploads();

                if ($this->autosaveThrows) {
                    $this->dispatchAutosaveValidationOrIdle();

                    throw $e;
                }

                $this->handleAutosaveFailure($e, 'save');
            } catch (\Throwable $e) {
                $this->discardAutosaveStoredUploads();
                $this->handleAutosaveFailure($e, 'save');

                if ($this->autosaveThrows) {
                    throw $e;
                }
            } finally {
                $this->autosaveCycleActive = false;
            }

            return;
        }

        $this->isAutosaving = true;

        try {
            if (method_exists($this, 'resetAutosaveRefreshState')) {
                $this->resetAutosaveRefreshState();
            }

            $this->authorizeAutosaveAccess();

            // Filament calls this before reading form state, so hooks can
            // normalize or populate values that the rest of the cycle sees.
            $this->callAutosaveHook('beforeValidate');

            $this->prepareAutosavePersistence();

            $data = $this->autosavePersistenceData();

            if (! $this->hasPendingAutosavePersistence() && $this->autosaveStore()->snapshotHash($data) === $this->autosaveSnapshotHash) {
                $this->dispatchAutosaveIdle();

                return;
            }

            // Hooks and validation receive the complete eligible form state.
            // Dirty-only filtering is a persistence concern: applying it here
            // would remove unchanged fields that cross-field rules or mutators
            // need to inspect.
            $data = $this->beforeAutosave($data);
            $data = $this->validateAutosaveFields($data);
            $data = $this->enforceFieldOptionRules($data);
            $this->syncAutosaveValidationErrors();

            if ($this->autosaveThrows && $this->autosaveValidationErrors !== []) {
                throw $this->autosaveValidationException();
            }

            $this->callAutosaveHook('afterValidate');

            if ($this->autosaveHasNothingToPersist($data)) {
                $this->finishAutosaveWithoutWrite();

                return;
            }

            $written = $this->runAutosavePersistence($persist, $data);

            if ($written === false) {
                $this->finishAutosaveWithoutWrite();

                return;
            }

            $this->autosaveSnapshotHash = $this->autosaveSuccessSnapshotHash(is_array($written) ? $written : $data);
            $this->commitAutosaveStoredUploads();
            $this->autosaveCycleWrote = true;

            $this->dispatch(
                AutosaveStatus::EVENT,
                status: $this->autosaveValidationErrors === []
                    ? AutosaveStatus::Saved->value
                    : AutosaveStatus::Validation->value,
                timestamp: now()->isoFormat('LT'),
                errors: $this->autosaveValidationErrors,
                pending: $this->autosavePendingFields,
                refreshed: method_exists($this, 'getAutosaveRefreshState')
                    ? $this->getAutosaveRefreshState()
                    : [],
            );
        } catch (Halt $e) {
            if ($this->autosaveCycleActive) {
                throw $e;
            }

            $this->discardAutosaveStoredUploads();
            $this->dispatchAutosaveIdle();
        } catch (\Throwable $e) {
            if ($this->autosaveCycleActive) {
                throw $e;
            }

            $this->discardAutosaveStoredUploads();
            $this->handleAutosaveFailure($e, 'save');
        } finally {
            $this->isAutosaving = false;
        }
    }

    /** Errors keyed by their Livewire state path, so Filament shows them inline. */
    protected function autosaveValidationException(): ValidationException
    {
        $root = trim($this->autosaveDataPath, '.');
        $messages = [];

        foreach ($this->autosaveValidationErrors as $key => $errors) {
            $messages[$root === '' ? (string) $key : $root.'.'.$key] = $errors;
        }

        return ValidationException::withMessages($messages);
    }

    protected function callAutosaveHook(string $hook): void
    {
        if (method_exists($this, 'callHook')) {
            $this->callHook($hook);

            return;
        }

        if (method_exists($this, $hook)) {
            $this->{$hook}();
        }
    }

    protected function dispatchAutosaveValidationOrIdle(): void
    {
        if ($this->autosaveValidationErrors === []) {
            $this->dispatchAutosaveIdle();

            return;
        }

        $this->dispatch(
            AutosaveStatus::EVENT,
            status: AutosaveStatus::Validation->value,
            errors: $this->autosaveValidationErrors,
            pending: $this->autosavePendingFields,
        );
    }

    /** Nothing reached the persistence callback: undo stored files and report. */
    protected function finishAutosaveWithoutWrite(): void
    {
        $this->discardAutosaveStoredUploads();
        $this->dispatchAutosaveValidationOrIdle();
    }

    /** Allow Edit pages to put hooks, writes, and events in one transaction. */
    protected function runAutosavePersistence(callable $persist, array $data): mixed
    {
        return $persist($data);
    }

    protected function runAutosaveCycle(callable $cycle): mixed
    {
        return $cycle();
    }

    protected function queueAutosaveSavedNotification(): void
    {
        $this->autosaveNotificationPending = true;
    }

    protected function flushAutosaveSavedNotification(): void
    {
        if (! $this->autosaveNotificationPending) {
            return;
        }

        $this->autosaveNotificationPending = false;

        if (method_exists($this, 'sendAutosaveSavedNotification')) {
            $this->sendAutosaveSavedNotification();
        }
    }

    protected function clearQueuedAutosaveNotification(): void
    {
        $this->autosaveNotificationPending = false;
    }

    /** Hooks for HasAutosaveUploads to clean up files around the cycle. */
    protected function discardAutosaveStoredUploads(): void {}

    protected function commitAutosaveStoredUploads(): void {}

    /** Whether the payload is empty and nothing else waits to be persisted. */
    protected function autosaveHasNothingToPersist(array $data): bool
    {
        return empty($data) && ! $this->hasPendingAutosavePersistence();
    }

    /** @return array<string, mixed> */
    protected function autosavePersistenceData(): array
    {
        return $this->prepareAutosavePayload($this->getAutosaveData());
    }

    protected function prepareAutosavePersistence(): void {}

    protected function hasPendingAutosavePersistence(): bool
    {
        return false;
    }

    /**
     * Override to tweak the payload before autosave rules run.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function beforeAutosave(array $data): array
    {
        return $data;
    }

    /**
     * Laravel rules for autosave; fields that fail are dropped without stalling the save.
     *
     * @return array<string, mixed>
     */
    protected function getAutosaveValidationRules(): array
    {
        return [];
    }

    /**
     * Drafts keep raw state so restoring them never re-applies transforms.
     *
     * @return array<string, mixed>
     */
    protected function getAutosaveData(): array
    {
        $form = $this->resolveAutosaveForm();

        if ($form === null) {
            return $this->stripFileUploads($this->data ?? []);
        }

        return $this->stripFileUploads($this->normalizeStateArray($form->getRawState()));
    }

    /**
     * Row keys inside repeaters become wildcards, e.g. items.*.title.
     *
     * @return array<string, array<object>> path pattern => component instances
     */
    protected function getAutosaveFields(): array
    {
        return $this->autosaveFieldsCache ??= $this->buildAutosaveFields();
    }

    /**
     * @return array<string, array<object>> path pattern => component instances
     */
    protected function buildAutosaveFields(): array
    {
        $form = $this->resolveAutosaveForm();

        if ($form === null || ! method_exists($form, 'getFlatFields')) {
            return [];
        }

        $flat = $form->getFlatFields(withHidden: true);
        $declared = array_fill_keys(array_map(strval(...), array_keys($flat)), true);

        $fields = [];

        foreach ($flat as $key => $field) {
            $fields[$this->normalizeAutosaveFieldPath((string) $key, $declared)][] = $field;
        }

        return $fields;
    }

    /** @param  array<string, true>  $declared */
    protected function normalizeAutosaveFieldPath(string $key, array $declared): string
    {
        return AutosaveFieldTree::normalizePath($key, $declared);
    }

    /**
     * @param  array<string>  $patterns
     * @return array<string, mixed>
     */
    protected function buildAutosaveFieldTree(array $patterns): array
    {
        return AutosaveFieldTree::buildTree($patterns);
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $tree
     * @return array<string, mixed>
     */
    protected function pruneStateToFieldTree(array $state, array $tree): array
    {
        return AutosaveFieldTree::prune($state, $tree);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string> concrete paths in $data matching a wildcarded pattern
     */
    protected function matchAutosavePaths(array $data, string $pattern): array
    {
        return AutosaveFieldTree::matchPaths($data, $pattern);
    }

    protected function autosavePathIsComplete(mixed $value, string $pattern): bool
    {
        return AutosaveFieldTree::isComplete($value, $pattern);
    }

    /**
     * Also strip emptied parents so stored containers aren't overwritten.
     *
     * @param  array<string, mixed>  $data
     */
    protected function forgetAutosavePath(array &$data, string $path): void
    {
        AutosaveFieldTree::forget($data, $path);
    }

    /**
     * Account for type('password'), which doesn't turn on isPassword().
     *
     * @param  array<object>  $fields
     */
    protected function isAutosaveSecretField(array $fields): bool
    {
        return AutosaveFieldMap::isSecret($fields);
    }

    /** @param  array<object>  $fields */
    protected function anyAutosaveField(array $fields, string $method): bool
    {
        return AutosaveFieldMap::any($fields, $method);
    }

    /** @return array<string, mixed> */
    protected function normalizeStateArray(mixed $state): array
    {
        return AutosaveState::normalize($state);
    }

    /**
     * Do not persist an explicitly blank required value during a partial save.
     *
     * The form validator normally catches this first, but upload preparation
     * also builds a safety payload before validation. Keeping this shared here
     * lets generic forms and Edit pages apply the same guard.
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
     * A nested container must be complete before it can be written as one
     * column value. This prevents a dirty-only child from wiping siblings.
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

    /** @param  array<string, mixed>  $draft */
    protected function fillAutosaveData(array $draft): void
    {
        $form = $this->resolveAutosaveForm();

        if ($form !== null) {
            $form->fill($draft);

            return;
        }

        $this->data = array_merge($this->data ?? [], $draft);
    }

    /**
     * Hash of the current state minus excluded, password, and file fields.
     */
    protected function currentAutosaveSnapshotHash(): string
    {
        return $this->autosaveStore()->snapshotHash(
            $this->prepareAutosavePayload($this->getAutosaveData())
        );
    }

    /**
     * Surface a failed autosave operation without leaking field values.
     */
    protected function handleAutosaveFailure(\Throwable $e, string $context): void
    {
        Log::warning("Autosave {$context} failed", ['exception' => $e::class]);

        $this->dispatch(AutosaveStatus::EVENT, status: AutosaveStatus::Error->value);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function prepareAutosavePayload(array $data): array
    {
        return $this->dropPasswordFields(
            $this->autosaveStore()->excludeFields(
                $this->stripFileUploads($data),
                $this->getAutosaveExcept(),
            )
        );
    }

    /**
     * Never persist half-typed passwords or park them in cache unencrypted.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function dropPasswordFields(array $data): array
    {
        AutosaveFieldTree::eachMatch(
            $data,
            $this->getAutosaveFields(),
            function (array &$data, array $fields, string $match): void {
                if ($this->isAutosaveSecretField($fields)) {
                    $this->forgetAutosavePath($data, $match);
                }
            },
        );

        return $data;
    }

    /**
     * Keep option values inside the choices declared by their fields.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function enforceFieldOptionRules(array $data): array
    {
        return AutosaveFieldRules::enforceOptionRules($data, $this->getAutosaveFields(), $this->autosaveValidationErrors);
    }

    /** @param  array<object>  $rules */
    protected function passesOptionRules(mixed $value, array $rules): bool
    {
        return AutosaveFieldRules::passesOptionRules($value, $rules);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    protected function validateAutosaveFields(array $fields): array
    {
        $this->clearAutosaveValidationErrors();
        $this->autosaveValidationErrors = [];
        $this->autosavePendingFields = [];

        $form = $this->resolveAutosaveForm();

        if ($form !== null && method_exists($form, 'getState')) {
            try {
                // This is Filament's validation/dehydration entry point. The
                // `false` flag is important: autosave owns relationship
                // persistence and must not save relationships as a side effect
                // of checking the form.
                $form->getState(false);
            } catch (ValidationException $exception) {
                $errors = $exception->errors();
                $root = method_exists($form, 'getStatePath') ? trim((string) $form->getStatePath(), '.') : '';

                foreach ($errors as $key => $messages) {
                    $key = (string) $key;

                    if ($root !== '' && str_starts_with($key, $root.'.')) {
                        $key = substr($key, strlen($root) + 1);
                    }

                    $this->autosaveValidationErrors[$key] = array_values($messages);

                    // A validation error inside a JSON/group/repeater value
                    // makes that whole persistence unit unsafe. Filament may
                    // already have normalized the invalid child while
                    // validating, so do not require the exact error path to
                    // remain in the working payload before removing its root.
                    $this->forgetAutosavePath($fields, AutosaveFieldTree::topLevelKey($key));
                }
            }

            // Custom package rules are intentionally applied after Filament's
            // validator, while component rules and dynamic schema callbacks
            // are now sourced exclusively from the form pipeline.
            return AutosaveFieldRules::applyRules(
                $fields,
                $this->getAutosaveValidationRules(),
                $this->autosaveValidationErrors,
            );
        }

        return AutosaveFieldRules::applyRules($fields, AutosaveFieldRules::merge(
            AutosaveFieldRules::componentRules($this->getAutosaveFields()),
            $this->getAutosaveValidationRules(),
        ), $this->autosaveValidationErrors);
    }

    protected function clearAutosaveValidationErrors(): void
    {
        if ($this->autosaveValidationKeys !== [] && method_exists($this, 'resetValidation')) {
            $this->resetValidation($this->autosaveValidationKeys);
        }

        $this->autosaveValidationKeys = [];
    }

    protected function syncAutosaveValidationErrors(): void
    {
        $errors = [];

        foreach ($this->autosaveValidationErrors as $key => $messages) {
            $label = $this->autosaveValidationLabel((string) $key);
            $messages = array_map(
                fn (string $message): string => $this->replaceAutosaveValidationAttribute($message, (string) $key, $label),
                $messages,
            );
            $errors[$key] = $messages;

            if (method_exists($this, 'addError')) {
                foreach ($messages as $message) {
                    $this->addError((string) $key, $message);
                }
            }
        }

        $this->autosaveValidationErrors = $errors;
        $this->autosaveValidationKeys = array_map(strval(...), array_keys($errors));
        $this->autosavePendingFields = array_values(array_map(strval(...), array_keys($errors)));
    }

    protected function autosaveValidationLabel(string $key): string
    {
        $field = $this->autosaveValidationField($key);

        if ($field !== null && method_exists($field, 'getLabel') && filled($field->getLabel())) {
            return (string) $field->getLabel();
        }

        return str($key)->replace(['_', '.'], ' ')->headline()->toString();
    }

    protected function autosaveValidationField(string $key): ?object
    {
        $fields = $this->getAutosaveFields();

        if (isset($fields[$key][0])) {
            return $fields[$key][0];
        }

        foreach ($fields as $pattern => $fieldSet) {
            if ($fieldSet !== [] && AutosaveFieldTree::matches($key, $pattern)) {
                return $fieldSet[0];
            }
        }

        return null;
    }

    protected function replaceAutosaveValidationAttribute(string $message, string $key, string $label): string
    {
        return str_ireplace([
            $key,
            str($key)->replace(['_', '.'], ' ')->toString(),
        ], $label, $message);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function stripFileUploads(array $data): array
    {
        return AutosaveState::stripUploads($data);
    }

    protected function resolveAutosaveForm(): ?object
    {
        $form = $this->form ?? null;

        if (is_object($form) && method_exists($form, 'getRawState') && method_exists($form, 'fill')) {
            return $form;
        }

        // Relation managers, table actions, and modal actions expose their
        // active form as a mounted schema rather than as `$this->form`.
        if (method_exists($this, 'getMountedActionSchema')) {
            try {
                $form = $this->getMountedActionSchema();

                if (is_object($form) && method_exists($form, 'getRawState') && method_exists($form, 'fill')) {
                    return $form;
                }
            } catch (\Throwable) {
                // A component may have no mounted action during mount/render.
            }

            // A table or relation manager's generic `form` schema can be a
            // filter schema, and resolving it while no action is mounted can
            // execute unrelated action definitions. Consumers can override
            // `resolveAutosaveForm()` when they intentionally autosave that
            // separate schema.
            return null;
        }

        // Relation managers and table components without mounted actions may
        // still expose a default form in the cached schema registry.
        if (method_exists($this, 'getSchema')) {
            try {
                $form = $this->getSchema('form');

                if (is_object($form) && method_exists($form, 'getRawState') && method_exists($form, 'fill')) {
                    return $form;
                }
            } catch (\Throwable) {
                // Some components only register action schemas lazily.
            }
        }

        return null;
    }
}
