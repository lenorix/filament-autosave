<?php

namespace Lenorix\FilamentAutosave;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Resources\Events\RecordSaved;
use Filament\Resources\Events\RecordUpdated;
use Filament\Resources\Pages\Page;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Lenorix\FilamentAutosave\Events\AutosaveConflict;
use Lenorix\FilamentAutosave\Events\AutosaveFailed;
use Lenorix\FilamentAutosave\Events\AutosaveSaved;
use Lenorix\FilamentAutosave\Events\AutosaveSkipped;
use Lenorix\FilamentAutosave\Events\AutosaveSynced;
use Lenorix\FilamentAutosave\Events\AutosaveUndone;
use Livewire\Attributes\Locked;

trait HasAutosaveBase
{
    use HasAutosaveMerge;

    // Expose the server decision to Alpine and lock it from the client.
    #[Locked]
    public bool $autosaveEnabled = true;

    #[Locked]
    public string $autosaveSnapshotHash = '';

    #[Locked]
    public int $autosaveDebounceMs = 0;

    /** Poll interval for pulling other editors' changes, in milliseconds; 0 disables polling. */
    #[Locked]
    public int $autosavePollMs = 0;

    /**
     * Hash of each raw record attribute as this component last observed it.
     * A poll compares against these to tell a remote write from noise.
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $autosaveSyncedAttributeHashes = [];

    /** @var array<string, mixed> Clean columns re-read from the record for the status event. */
    protected array $autosaveRefreshState = [];

    /** Livewire path watched by the indicator; defaults to the Filament form path. */
    #[Locked]
    public string $autosaveDataPath = 'data';

    /**
     * Validation failures remain visible while valid sibling fields save.
     *
     * @var array<string, array<int, string>>
     */
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

    /**
     * Top-level paths the current cycle handed to the record write, set right
     * before it. A Halt raised after that point, with Filament's default of
     * keeping the transaction, has committed these.
     *
     * @var array<int, string>
     */
    protected array $autosaveWrittenPaths = [];

    /** Whether the transaction wrapper committed on a Halt instead of rolling back. */
    protected bool $autosaveHaltCommitted = false;

    /** Notifications are sent only after the surrounding write commits. */
    protected bool $autosaveNotificationPending = false;

    /** Undo is unsafe when the configured relationship depth truncates a graph. */
    protected bool $autosaveRelationshipUndoTruncated = false;

    /** @var array<string, array<object>>|null Cached field map for this request. */
    protected ?array $autosaveFieldsCache = null;

    protected AutosaveStore $autosaveStore;

    protected function autosaveStore(): AutosaveStore
    {
        return $this->autosaveStore ??= app(AutosaveStore::class);
    }

    /**
     * Livewire's component id survives every request of one tab and differs
     * per tab, which is exactly the granularity an Undo target needs. Doubles
     * without Livewire fall back to no instance segment.
     */
    protected function autosaveUndoInstanceId(): ?string
    {
        if (! method_exists($this, 'getId')) {
            return null;
        }

        $id = $this->getId();

        return is_string($id) && $id !== '' ? $id : null;
    }

    protected function authorizeAutosaveAccess(): void
    {
        if (method_exists($this, 'authorizeAccess')) {
            $this->authorizeAccess();
        }
    }

    protected function dispatchAutosaveIdle(): void
    {
        $this->dispatchAutosaveStatus(AutosaveStatus::Idle);
    }

    /**
     * Push one of the small autosave status events to the frontend.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function dispatchAutosaveStatus(AutosaveStatus $status, array $extra = []): void
    {
        // ->self(): the indicator listens with $wire.$on() inside this
        // component. A plain dispatch from a component nested in a page is
        // only delivered to global Livewire.on() listeners, so the indicator
        // stuck at "saving" and its guard then dropped every later save.
        $event = $this->dispatch(AutosaveStatus::EVENT, ...['status' => $status->value, ...$extra]);

        if (is_object($event) && method_exists($event, 'self')) {
            $event->self();
        }
    }

    /** Fire the record events Filament pages emit after a save. */
    /**
     * Untyped like `handleRecordUpdate()`: production always passes a real
     * Eloquent model, but the parameter stays duck-typed so callers are not
     * forced into an Eloquent dependency they may not have.
     *
     * `RecordUpdated`/`RecordSaved` declare a constructor, so dispatching
     * them by class name with an array payload (the pattern Filament's own
     * `EditRecord::save()` uses) never builds that object: Laravel spreads
     * the array positionally into each listener instead, which throws a
     * `TypeError` for any listener type-hinted against the event class, and
     * that error was silently swallowed by the autosave failure handler.
     * Real instances are built whenever the record and this component
     * satisfy the constructor; otherwise a generic Livewire component
     * (a relation manager, a bare form) cannot supply a real
     * `Filament\Resources\Pages\Page`, so the payload falls back to
     * Filament's own convention for parity, with the same caveat.
     *
     * @param  array<string, mixed>  $data
     */
    protected function dispatchAutosaveRecordEvents(object $record, array $data): void
    {
        // Filament\Resources\Events only exists from later 4.x releases; on
        // 4.0.x there is nothing to dispatch and `new` would be a fatal error.
        if (! class_exists(RecordUpdated::class) || ! class_exists(RecordSaved::class)) {
            return;
        }

        if ($record instanceof Model && $this instanceof Page) {
            Event::dispatch(new RecordUpdated($record, $data, $this));
            Event::dispatch(new RecordSaved($record, $data, $this));

            return;
        }

        Event::dispatch(RecordUpdated::class, ['record' => $record, 'data' => $data, 'page' => $this]);
        Event::dispatch(RecordSaved::class, ['record' => $record, 'data' => $data, 'page' => $this]);
    }

    /**
     * Whether autosave runs at all for this component (server-side only).
     *
     * @api
     */
    protected function shouldAutosave(): bool
    {
        return true;
    }

    /**
     * Return milliseconds; null uses plugin or config.
     *
     * @api
     */
    protected function autosaveDebounce(): ?int
    {
        return null;
    }

    /**
     * @return array<string>
     *
     * @api
     */
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

    /**
     * Return the snapshot hash to keep after a successful write.
     *
     * @param  array<string, mixed>  $written
     */
    protected function autosaveSuccessSnapshotHash(array $written): string
    {
        return $this->currentAutosaveSnapshotHash();
    }

    /**
     * Resolved enable flag exposed to the indicator.
     *
     * @api
     */
    public function isAutosaveEnabled(): bool
    {
        return $this->autosaveEnabled && $this->shouldAutosave();
    }

    /**
     * Resolved debounce in milliseconds (page, then plugin, then config).
     *
     * @api
     */
    public function getAutosaveDebounce(): int
    {
        $pageDebounce = $this->autosaveDebounce();

        if ($pageDebounce !== null && $pageDebounce > 0) {
            return $pageDebounce;
        }

        return AutosavePlugin::resolve()->getDebounce();
    }

    /**
     * Return milliseconds between polls for other editors' changes; null uses
     * plugin or config, 0 disables polling for this component.
     *
     * @api
     */
    protected function autosavePollInterval(): ?int
    {
        return null;
    }

    /**
     * Resolved poll interval in milliseconds (page, then plugin, then config);
     * 0 means off. A poll can only refill through the unchanged-field refresh,
     * so it is off whenever that is (`refresh_unchanged_fields`, `dirty_only`).
     *
     * @api
     */
    public function getAutosavePollInterval(): int
    {
        if (! $this->autosaveRefreshEnabled()) {
            return 0;
        }

        $pageInterval = $this->autosavePollInterval();

        if ($pageInterval !== null) {
            return max(0, $pageInterval);
        }

        return max(0, AutosavePlugin::resolve()->getPollInterval());
    }

    /**
     * @return array<string>
     *
     * @api
     */
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

    /**
     * Override when a page keeps its form state outside the default `data` path.
     *
     * @api
     */
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
     *
     * @api
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
        // The browser is waiting for a status: answer a page that turned
        // autosave off with idle rather than leaving it at "saving". A
        // re-entrant call stays silent — the running cycle answers.
        if (! $this->isAutosaveEnabled()) {
            if (! $this->isAutosaving && ! $this->autosaveCycleActive) {
                $this->dispatchAutosaveIdle();
            }

            return;
        }

        if ($this->isAutosaving) {
            return;
        }

        // Outer call: own the cycle, wrap it in the transaction, and turn
        // whatever escapes it into an indicator status (or rethrow for
        // flushAutosave()). The cycle re-enters this method with
        // $autosaveCycleActive set and runs the phases below.
        if (! $this->autosaveCycleActive) {
            $this->runGuardedAutosaveCycle($persist);

            return;
        }

        $this->isAutosaving = true;

        try {
            $this->runAutosavePhases($persist);
        } finally {
            $this->isAutosaving = false;
        }
    }

    /**
     * The single place where a failing cycle is handled.
     *
     * Every exception thrown by a phase reaches this catch: the inner call
     * runs with $autosaveCycleActive set, so it never handles errors itself.
     * Halt is Filament's "stop quietly" signal, validation aborts before any
     * write, anything else is reported as an autosave error; with
     * flushAutosave() active each is rethrown after the same cleanup.
     */
    protected function runGuardedAutosaveCycle(callable $persist): void
    {
        $this->autosaveCycleActive = true;
        $this->autosaveCycleWrote = false;
        $this->autosaveWrittenPaths = [];
        $this->autosaveHaltCommitted = false;
        $this->resetAutosaveMergeReport();

        try {
            $this->runAutosaveCycle(fn () => $this->performAutosave($persist));
        } catch (Halt $e) {
            // Halt's default keeps the transaction: a Halt raised after the
            // write (an afterSave hook) has committed the columns, so the
            // files they point at must stay and the write be acknowledged,
            // or the next cycle rewrites it and the column names a deleted file.
            $this->autosaveHaltCommittedWrite()
                ? $this->autosaveCommitPhase([])
                : $this->discardAutosaveStoredUploads();
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
    }

    /**
     * authorize → prepare → validate → persist → commit → report.
     *
     * A phase returns null to end the cycle early; each early exit has
     * already reported its own status.
     */
    protected function runAutosavePhases(callable $persist): void
    {
        $this->autosaveAuthorizePhase();

        $data = $this->autosavePreparePhase();

        if ($data === null) {
            return;
        }

        $data = $this->autosaveValidatePhase($data);

        if ($data === null) {
            return;
        }

        $written = $this->autosavePersistPhase($persist, $data);

        if ($written === null) {
            return;
        }

        $this->autosaveCommitPhase($written);
        $this->autosaveReportPhase($written);
    }

    protected function autosaveAuthorizePhase(): void
    {
        if (method_exists($this, 'resetAutosaveRefreshState')) {
            $this->resetAutosaveRefreshState();
        }

        $this->authorizeAutosaveAccess();
    }

    /**
     * Collect the eligible state. Returns null when nothing changed since the
     * last acknowledged snapshot, which is the common idle tick.
     *
     * @return array<string, mixed>|null
     */
    protected function autosavePreparePhase(): ?array
    {
        // Filament calls this before reading form state, so hooks can
        // normalize or populate values that the rest of the cycle sees.
        $this->callAutosaveHook('beforeValidate');

        $this->premergeAutosaveRichFields();
        $this->prepareAutosavePersistence();

        $data = $this->autosavePersistenceData();

        if (! $this->hasPendingAutosavePersistence() && $this->autosaveStore()->snapshotHash($data) === $this->autosaveSnapshotHash) {
            $this->dispatchAutosaveIdle();
            Event::dispatch(new AutosaveSkipped($this, 'unchanged', [], []));

            return null;
        }

        return $data;
    }

    /**
     * Run hooks and validation over the complete eligible state and drop
     * what may not be written. Returns null when nothing is left to persist.
     *
     * Dirty-only filtering is a persistence concern: applying it here would
     * remove unchanged fields that cross-field rules or mutators need to
     * inspect.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    protected function autosaveValidatePhase(array $data): ?array
    {
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

            return null;
        }

        return $data;
    }

    /**
     * Hand the state to the trait's persistence callback. Returns the
     * written payload, or null when the callback declined to write.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    protected function autosavePersistPhase(callable $persist, array $data): ?array
    {
        $written = $this->runAutosavePersistence($persist, $data);

        if ($written === false) {
            $this->finishAutosaveWithoutWrite();

            return null;
        }

        return is_array($written) ? $written : $data;
    }

    /** A Halt after the record write, with the transaction kept: the write is in. */
    protected function autosaveHaltCommittedWrite(): bool
    {
        return $this->autosaveHaltCommitted && $this->autosaveWrittenPaths !== [];
    }

    /**
     * Acknowledge the write: snapshot hash, staged uploads, cycle flag.
     *
     * @param  array<string, mixed>  $written
     */
    protected function autosaveCommitPhase(array $written): void
    {
        $this->autosaveSnapshotHash = $this->autosaveSuccessSnapshotHash($written);
        $this->commitAutosaveStoredUploads();
        $this->autosaveCycleWrote = true;
    }

    /**
     * Tell listeners and the indicator what happened.
     *
     * @param  array<string, mixed>  $written
     */
    protected function autosaveReportPhase(array $written): void
    {
        Event::dispatch(new AutosaveSaved(
            $this,
            $this->autosaveEventRecord(),
            $written,
            $this->autosavePendingFields,
            $this->autosaveMergedValues,
            $this->autosaveMergeConflicts,
        ));
        $this->dispatchAutosaveContended();

        // A field still contended after every retry was not written: the
        // cycle is reported like one with pending fields, not as a clean save.
        $clean = $this->autosaveValidationErrors === [] && $this->autosaveContendedValues === [];

        $this->dispatchAutosaveStatus(
            $clean ? AutosaveStatus::Saved : AutosaveStatus::Validation,
            [
                'timestamp' => now()->isoFormat('LT'),
                'errors' => $this->autosaveValidationErrors,
                'pending' => $this->autosavePendingFields,
                'refreshed' => method_exists($this, 'getAutosaveRefreshState')
                    ? $this->getAutosaveRefreshState()
                    : [],
                ...$this->autosaveMergeReport(),
            ],
        );
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

        $this->dispatchAutosaveStatus(AutosaveStatus::Validation, [
            'errors' => $this->autosaveValidationErrors,
            'pending' => $this->autosavePendingFields,
        ]);
    }

    /** Nothing reached the persistence callback: undo stored files and report. */
    protected function finishAutosaveWithoutWrite(): void
    {
        $this->discardAutosaveStoredUploads();
        $this->dispatchAutosaveValidationOrIdle();

        Event::dispatch(new AutosaveSkipped(
            $this,
            $this->autosaveValidationErrors === [] ? 'unchanged' : 'validation',
            $this->autosavePendingFields,
            $this->autosaveValidationErrors,
        ));
    }

    /** The record an event should carry; overridden where the trait knows one. */
    protected function autosaveEventRecord(): ?object
    {
        return null;
    }

    protected function dispatchAutosaveUndone(): void
    {
        $this->dispatchAutosaveStatus(AutosaveStatus::Undone);
        Event::dispatch(new AutosaveUndone($this, $this->autosaveEventRecord()));
    }

    protected function dispatchAutosaveConflict(): void
    {
        $this->dispatchAutosaveStatus(AutosaveStatus::Conflict);
        Event::dispatch(new AutosaveConflict($this, $this->autosaveEventRecord()));
    }

    /**
     * Allow Edit pages to put hooks, writes, and events in one transaction.
     *
     * @param  array<string, mixed>  $data
     */
    protected function runAutosavePersistence(callable $persist, array $data): mixed
    {
        return $persist($data);
    }

    protected function runAutosaveCycle(callable $cycle): mixed
    {
        return $cycle();
    }

    /**
     * Wrap the autosave write in one database transaction.
     *
     * Filament's `beginDatabaseTransaction()` and friends are no-ops unless
     * the host opted in with `Panel::databaseTransactions()`, which is off by
     * default. An autosave writes columns, then relationship rows, then runs
     * hooks; without a transaction a failure part-way through leaves the
     * columns written and the rows not. When the panel owns transactions
     * its methods are used so a page's own nesting stays intact; otherwise
     * the package opens its own.
     */
    protected function autosaveWithinDatabaseTransaction(callable $write): mixed
    {
        if (! $this->autosavePanelOwnsDatabaseTransactions()) {
            return $this->autosaveWithoutDatabaseTransaction($write);
        }

        try {
            $this->beginDatabaseTransaction();
            $result = $write();
            $this->commitDatabaseTransaction();

            return $result;
        } catch (Halt $exception) {
            if ($exception->shouldRollbackDatabaseTransaction()) {
                $this->rollBackDatabaseTransaction();
            } else {
                $this->commitDatabaseTransaction();
                $this->autosaveHaltCommitted = true;
            }

            throw $exception;
        } catch (\Throwable $exception) {
            $this->rollBackDatabaseTransaction();

            throw $exception;
        }
    }

    protected function autosavePanelOwnsDatabaseTransactions(): bool
    {
        return method_exists($this, 'beginDatabaseTransaction')
            && method_exists($this, 'commitDatabaseTransaction')
            && method_exists($this, 'rollBackDatabaseTransaction')
            && (! method_exists($this, 'hasDatabaseTransactions') || $this->hasDatabaseTransactions());
    }

    /** The package's own transaction, honouring Halt's rollback flag like Filament does. */
    protected function autosaveWithoutDatabaseTransaction(callable $write): mixed
    {
        DB::beginTransaction();

        try {
            $result = $write();
            DB::commit();

            return $result;
        } catch (Halt $exception) {
            if ($exception->shouldRollbackDatabaseTransaction()) {
                DB::rollBack();
            } else {
                DB::commit();
                $this->autosaveHaltCommitted = true;
            }

            throw $exception;
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
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

        $this->sendAutosaveSavedNotification();
    }

    protected function sendAutosaveSavedNotification(): void
    {
        $notification = method_exists($this, 'getSavedNotification')
            ? $this->getSavedNotification()
            : null;

        if ($notification !== null && method_exists($notification, 'send')) {
            $notification->send();
        }
    }

    protected function clearQueuedAutosaveNotification(): void
    {
        $this->autosaveNotificationPending = false;
    }

    /** Hooks for HasAutosaveUploads to clean up files around the cycle. */
    protected function discardAutosaveStoredUploads(): void {}

    protected function commitAutosaveStoredUploads(): void {}

    /**
     * Whether the payload is empty and nothing else waits to be persisted.
     *
     * @param  array<string, mixed>  $data
     */
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
     *
     * @api
     */
    protected function beforeAutosave(array $data): array
    {
        return $data;
    }

    /**
     * Laravel rules for autosave; fields that fail are dropped without stalling the save.
     *
     * @return array<string, mixed>
     *
     * @api
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
        foreach ($this->getAutosaveFields() as $path => $fields) {
            if (! str_contains($path, '.') || $this->autosaveFieldsLiveOutsideColumns($fields)) {
                continue;
            }

            $top = AutosaveFieldTree::topLevelKey($path);

            if (array_key_exists($top, $data) && ! $this->autosavePathIsComplete($data, $path)) {
                unset($data[$top]);
            }
        }

        return $data;
    }

    /**
     * Media-library fields persist through their own callback and dehydrate
     * to nothing, so their absence from a container's column data does not
     * make that container incomplete.
     *
     * @param  array<int, object>  $fields
     */
    protected function autosaveFieldsLiveOutsideColumns(array $fields): bool
    {
        if ($fields === []) {
            return false;
        }

        foreach ($fields as $field) {
            if (! $field instanceof SpatieMediaLibraryFileUpload) {
                return false;
            }
        }

        return true;
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

        $this->dispatchAutosaveStatus(AutosaveStatus::Error);
        Event::dispatch(new AutosaveFailed($this, $e, $context));
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
        $this->autosavePendingFields = array_map(strval(...), array_keys($errors));
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

    /** Whether `form()` is defined by the application, not inherited from Filament. */
    protected function declaresOwnAutosaveForm(): bool
    {
        if (! method_exists($this, 'form')) {
            return false;
        }

        $declaringClass = (new \ReflectionMethod($this, 'form'))->getDeclaringClass()->getName();

        return ! str_starts_with($declaringClass, 'Filament\\');
    }

    /**
     * Record changed fields that this cycle left unsaved for a reason other
     * than a validation message, so the indicator can list them.
     *
     * @param  array<int, string>  $paths
     */
    protected function markAutosavePendingFields(array $paths): void
    {
        $this->autosavePendingFields = array_values(array_unique([
            ...$this->autosavePendingFields,
            ...array_map(strval(...), $paths),
        ]));
    }

    /**
     * The schema autosave reads and writes; override for mounted-action or custom schemas.
     *
     * @api
     */
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
            // execute unrelated action definitions. Only fall through when the
            // component itself declares `form()`, which signals a real form.
            if (! $this->declaresOwnAutosaveForm()) {
                return null;
            }
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

    // ---------------------------------------------------------------------------
    // Relationship undo machinery, shared by resource pages and generic forms.
    // ---------------------------------------------------------------------------

    /**
     * Turn dates and enums into scalars without losing JSON-cast arrays.
     *
     * @template TSnapshot of array<array-key, mixed>
     *
     * @param  TSnapshot  $data
     * @return TSnapshot
     */
    protected function normalizeUndoSnapshot(array $data): array
    {
        return AutosaveStore::normalizeScalars($data);
    }

    protected function autosaveRelativeFieldPath(object $field): ?string
    {
        if (! method_exists($field, 'getStatePath') || ! filled($fieldPath = $field->getStatePath())) {
            return null;
        }

        return AutosaveFieldTree::relativePath((string) $fieldPath, $this->getAutosaveStatePath());
    }

    /**
     * Snapshot every pending relationship field under a cacheable path.
     *
     * @param  array<string, array<object>>  $fieldsByPath
     * @return array<string, array<string, mixed>>
     */
    protected function captureAutosaveRelationshipUndoFields(array $fieldsByPath): array
    {
        $this->autosaveRelationshipUndoTruncated = false;
        $snapshot = [];

        foreach ($fieldsByPath as $path => $fields) {
            foreach ($fields as $index => $field) {
                $snapshotPath = count($fields) === 1
                    ? $path
                    : ($this->autosaveRelativeFieldPath($field) ?? $path.'.'.$index);

                if ($this->autosaveRelationshipUndoDepth($snapshotPath)
                    > max(1, (int) config('filament-autosave.relationship_undo_depth', 8))) {
                    $this->autosaveRelationshipUndoTruncated = true;

                    continue;
                }

                $captured = $this->captureAutosaveRelationshipUndoField($field);

                if ($captured !== null) {
                    $snapshot[$snapshotPath] = $captured;
                }
            }
        }

        return $snapshot;
    }

    /** Count nested state segments to bound recursive relationship snapshots. */
    protected function autosaveRelationshipUndoDepth(string $path): int
    {
        return max(1, count(array_filter(explode('.', trim($path, '.')), static fn (string $segment): bool => $segment !== '*')));
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

    /**
     * @param  MorphTo<Model, Model>  $relation
     * @return array<string, mixed>
     */
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

    /**
     * @param  BelongsToMany<Model, Model>  $relation
     * @return array<int, array<string, mixed>>
     */
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

    /**
     * @param  HasOneOrMany<Model, Model, mixed>|HasOneOrManyThrough<Model, Model, Model, mixed>  $relation
     * @return array<int, array<string, mixed>>
     */
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
     * Relationship fields keyed by state path, with their component instances.
     * Edit pages and record-backed generic forms provide the real map; a
     * draft-only host has none.
     *
     * @return array<string, array<int, object>>
     */
    protected function autosaveRelationshipFields(): array
    {
        return [];
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

    /**
     * @param  MorphTo<Model, Model>  $relation
     * @param  array<string, mixed>  $attributes
     */
    protected function restoreMorphToUndo(MorphTo $relation, array $attributes): void
    {
        if ($attributes !== []) {
            $relation->getParent()->forceFill($attributes)->save();
        }
    }

    /**
     * @param  BelongsToMany<Model, Model>  $relation
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function restoreBelongsToManyUndo(BelongsToMany $relation, array $rows): void
    {
        $ids = [];

        foreach ($rows as $row) {
            $ids[$row['key']] = $row['pivot'] ?? [];
        }

        $relation->sync($ids);
    }

    /**
     * @param  HasOneOrManyThrough<Model, Model, Model, mixed>  $relation
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
        $existing = $this->autosaveCurrentRelatedRows($relation);
        $this->deleteAutosaveRowsMissingFrom($existing, $original);

        foreach ($original as $attributes) {
            $this->restoreAutosaveRelatedModel($related, $attributes, $keyName, $existing)->save();
        }
    }

    /**
     * @param  HasOneOrMany<Model, Model, mixed>  $relation
     * @param  array<int, array<string, mixed>>  $rows
     */
    protected function restoreHasManyUndo(HasOneOrMany $relation, array $rows): void
    {
        $related = $relation->getRelated();
        $keyName = $related->getKeyName();
        $original = $this->autosaveRelatedRowsByKey($rows, $keyName);

        $existing = $this->autosaveCurrentRelatedRows($relation);
        $this->deleteAutosaveRowsMissingFrom($existing, $original);

        foreach ($original as $attributes) {
            $relation->save($this->restoreAutosaveRelatedModel($related, $attributes, $keyName, $existing));
        }
    }

    /**
     * Fetch every row currently on the relation in one query. Undo reuses it
     * both to find rows to delete and, keyed by primary key, to update rows
     * that survived instead of issuing a `whereKey()` lookup per row.
     *
     * @return Collection<string, object>
     */
    protected function autosaveCurrentRelatedRows(object $relation): Collection
    {
        return $relation->get()->keyBy(
            fn (object $model): string => (string) $model->getKey(),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  Collection<string, object>|null  $existing
     */
    protected function restoreAutosaveRelatedModel(object $related, array $attributes, string $keyName, ?Collection $existing = null): object
    {
        $key = (string) ($attributes[$keyName] ?? '');
        $model = $existing?->get($key) ?? $related->newQuery()->whereKey($attributes[$keyName])->first()
            ?? $related->newInstance();

        return $model->forceFill($attributes);
    }

    /**
     * Delete current rows that were not part of the original snapshot.
     *
     * @param  Collection<string, object>  $current
     * @param  array<string, array<string, mixed>>  $original
     */
    protected function deleteAutosaveRowsMissingFrom(Collection $current, array $original): void
    {
        foreach ($current as $key => $model) {
            if (! array_key_exists((string) $key, $original)) {
                $model->delete();
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

    // ---------------------------------------------------------------------------
    // Refreshing clean fields from the record: after this component's own save
    // and, by polling, when another editor writes.
    // ---------------------------------------------------------------------------

    /**
     * Pull other editors' changes into the fields this user is not editing.
     *
     * Called by the browser on a timer. Reads the record once, refills the
     * clean, model-backed columns another editor changed, and reports fields
     * that are dirty locally *and* changed remotely as `stale` without
     * touching them. Emits a `synced` status only when something changed, so
     * an idle page stays silent. Never writes to the database and never
     * touches Undo snapshots. Drafts and Create pages are a no-op.
     *
     * A stale field listed as mergeable also gets the other editor's current
     * value in `patches`, unless the browser sent that value's hash in
     * `$mergeBaseHashes` (path => xxh128), meaning it already holds it.
     *
     * @param  array<string, string>  $mergeBaseHashes
     *
     * @api
     */
    public function syncAutosave(array $mergeBaseHashes = []): void
    {
        if (! $this->isAutosaveEnabled() || ! $this->autosaveRefreshEnabled() || $this->isAutosaving || $this->autosaveCycleActive) {
            return;
        }

        $record = $this->autosaveSyncRecord();

        if (! is_object($record) || ! method_exists($record, 'getAttributes') || ! ($record->exists ?? false)) {
            return;
        }

        $this->authorizeAutosaveAccess();

        try {
            if (! $this->autosaveRecordChangedRemotely($record)) {
                return;
            }

            $changed = $this->autosaveChangedRecordAttributes($record);

            if ($changed === []) {
                $this->rememberAutosaveSyncedAttributes($record);

                return;
            }

            $this->autosaveFieldsCache = null;
            $current = $this->prepareAutosavePayload($this->getAutosaveData());
            $plan = AutosaveSync::plan(
                $changed,
                $current,
                $this->autosaveRefreshablePaths($record, $current),
                $this->autosaveMergeablePaths(),
                array_filter($mergeBaseHashes, 'is_string'),
                $record->getAttributes(),
                $this->autosaveFieldIsClean(...),
                $this->autosavePathExcluded(...),
                fn (string $path, mixed $raw): mixed => $this->autosaveMergeRemoteValue($record, $path, $raw),
            );

            $refreshed = $this->refillAutosavePaths($record, $plan['refill']);
            // Only now: a failed refill must leave the change visible to the
            // next poll, or the timestamp fast path would hide it for good.
            $this->rememberAutosaveSyncedAttributes($record);
        } catch (\Throwable $e) {
            $this->handleAutosaveFailure($e, 'sync');

            return;
        }

        if ($refreshed === [] && $plan['stale'] === []) {
            return;
        }

        $this->dispatchAutosaveStatus(AutosaveStatus::Synced, AutosaveSync::syncedPayload($refreshed, $plan['stale'], $plan['patches']));
        Event::dispatch(new AutosaveSynced($this, $record, $refreshed, $plan['stale'], $plan['patches']));
    }

    /** The record polling reads; traits that own one override this. */
    protected function autosaveSyncRecord(): ?object
    {
        return null;
    }

    /**
     * Whether this component can refill form fields from its record. The
     * hooks below stay no-ops on traits that cannot (Create pages).
     */
    protected function autosaveCanRefillFromRecord(): bool
    {
        return false;
    }

    /** Whether a top-level field still matches the value last acknowledged as saved. */
    protected function autosaveFieldIsClean(string $path, mixed $value): bool
    {
        return false;
    }

    /** Record a refreshed value as the field's new acknowledged state. */
    protected function acknowledgeAutosaveRefreshedField(string $path, mixed $value): void {}

    /**
     * Fill the given top-level paths from the record, applying the same
     * casts and fill hooks a normal form fill would.
     *
     * @param  array<int, string>  $paths
     */
    protected function refillAutosaveFieldsFromRecord(object $record, array $paths): void {}

    /**
     * Fill top-level paths of a schema from an attribute array, then run the
     * hydration hooks and state casts for just those paths. Unlike
     * `Schema::fillPartially()`, which flattens the state with dot notation
     * and so never matches an array attribute (a JSON column, a RichEditor
     * document), this keeps each value whole.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $paths
     */
    protected function fillAutosavePathsPartially(object $form, array $attributes, array $paths): void
    {
        if (! method_exists($form, 'partialRawState') || ! method_exists($form, 'hydrateStatePartially')) {
            return;
        }

        $state = array_intersect_key($attributes, array_flip($paths));

        if ($state === []) {
            return;
        }

        $form->partialRawState($state);

        $prefix = method_exists($form, 'getStatePath') ? (string) $form->getStatePath() : '';
        $form->hydrateStatePartially(array_map(
            static fn (string $path): string => $prefix === '' ? $path : "{$prefix}.{$path}",
            array_keys($state),
        ), true);

        if (method_exists($form, 'fillStateWithNull')) {
            $form->fillStateWithNull();
        }
    }

    /**
     * Per-field hashes, the same for Edit pages and generic forms so a snapshot
     * written by one reads back correctly in the other.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    protected function hashAutosaveFields(array $data): array
    {
        $hashes = [];

        foreach ($data as $key => $value) {
            $hashes[(string) $key] = $this->hashAutosaveValue($value);
        }

        return $hashes;
    }

    /** Hash one value the same way autosave field hashes are built. */
    protected function hashAutosaveValue(mixed $value): string
    {
        return $this->autosaveStore()->snapshotHash(['value' => $value]);
    }

    /**
     * Single source of truth for `dirty_only`; every trait reads it here so the
     * fallback cannot drift between Edit pages and generic forms.
     */
    protected function autosaveDirtyOnly(): bool
    {
        return (bool) config('filament-autosave.dirty_only', true);
    }

    /**
     * How long an Undo snapshot stays available.
     *
     * @api
     */
    protected function getUndoTtlMinutes(): int
    {
        return AutosavePlugin::resolve()->getUndoCacheTtl();
    }

    protected function autosaveRefreshEnabled(): bool
    {
        return $this->autosaveDirtyOnly()
            && (bool) config('filament-autosave.refresh_unchanged_fields', true);
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
     * After this component's own write: re-read every clean, model-backed
     * column so another editor's changes to untouched fields show up in the
     * same response. Shared by the save path and the poll so the two can
     * never disagree on which fields are eligible.
     */
    protected function refreshAutosaveFieldsFromRecord(object $record): void
    {
        $this->autosaveRefreshState = [];
        $this->rememberAutosaveSyncedAttributes($record);

        if (! $this->autosaveRefreshEnabled() || ! method_exists($record, 'attributesToArray')) {
            return;
        }

        $current = $this->prepareAutosavePayload($this->getAutosaveData());
        $paths = array_keys($this->autosaveRefreshablePaths($record, $current));

        if ($paths === []) {
            return;
        }

        try {
            $this->autosaveRefreshState = $this->refillAutosavePaths($record, $paths, onlyChanged: false);
        } catch (\Throwable $e) {
            Log::warning('Autosave unchanged-field refresh failed', ['exception' => $e::class]);
        }
    }

    /**
     * Top-level paths eligible for a refresh: clean by hash, not a
     * relationship, not an upload, not excluded, and backed by a record
     * attribute.
     *
     * @param  array<string, mixed>  $current  Prepared payload of the live form.
     * @return array<string, true>
     */
    protected function autosaveRefreshablePaths(object $record, array $current): array
    {
        if (! $this->autosaveCanRefillFromRecord() || ! method_exists($record, 'attributesToArray')) {
            return [];
        }

        $skip = [];

        foreach ($current as $path => $value) {
            if (! $this->autosaveFieldIsClean((string) $path, $value)) {
                $skip[AutosaveFieldTree::topLevelKey((string) $path)] = true;
            }
        }

        // A RichEditor is a column with an attachment callback; listed as
        // mergeable, it refills like any other column.
        $mergeable = $this->autosaveMergeablePaths();

        foreach (array_keys($this->autosaveRelationshipFields()) as $path) {
            $top = AutosaveFieldTree::topLevelKey((string) $path);

            if (! isset($mergeable[$top]) || ! $this->autosaveMergeComponent($top) instanceof RichEditor) {
                $skip[$top] = true;
            }
        }

        if (method_exists($this, 'autosaveUploadFields')) {
            foreach (array_keys($this->autosaveUploadFields()) as $path) {
                $skip[AutosaveFieldTree::topLevelKey((string) $path)] = true;
            }
        }

        // A generic form filled with attributesToArray() carries every model
        // column in its state, not just the schema's fields. Only declared
        // fields may be refilled or reported as refreshed.
        $declared = [];

        foreach (array_keys($this->getAutosaveFields()) as $path) {
            $declared[AutosaveFieldTree::topLevelKey((string) $path)] = true;
        }

        $attributes = $record->attributesToArray();
        $paths = [];

        foreach (array_keys($current) as $path) {
            $top = AutosaveFieldTree::topLevelKey((string) $path);

            if (isset($declared[$top]) && ! isset($skip[$top])
                && ! $this->autosavePathExcluded($top) && array_key_exists($top, $attributes)) {
                $paths[$top] = true;
            }
        }

        return $paths;
    }

    /**
     * Refill paths from the record and acknowledge their new values.
     *
     * @param  array<int, string>  $paths
     * @param  bool  $onlyChanged  Report only paths whose value actually changed
     *                             (a poll), or every refilled path (post-save).
     * @return array<string, mixed> path => value, for the status event.
     */
    protected function refillAutosavePaths(object $record, array $paths, bool $onlyChanged = true): array
    {
        if ($paths === []) {
            return [];
        }

        $this->refillAutosaveFieldsFromRecord($record, $paths);

        $this->autosaveFieldsCache = null;
        $after = $this->prepareAutosavePayload($this->getAutosaveData());
        $refreshed = [];

        foreach ($paths as $path) {
            if (! array_key_exists($path, $after)) {
                continue;
            }

            $unchanged = $this->autosaveFieldIsClean($path, $after[$path]);
            $this->acknowledgeAutosaveRefreshedField($path, $after[$path]);

            if (! $onlyChanged || ! $unchanged) {
                $refreshed[$path] = $after[$path];
            }
        }

        return $refreshed;
    }

    /**
     * Cheapest possible "did anyone else write?" check: a timestamped model
     * costs one narrow query when nothing changed; otherwise one refresh.
     */
    protected function autosaveRecordChangedRemotely(object $record): bool
    {
        if (method_exists($record, 'usesTimestamps') && $record->usesTimestamps()
            && method_exists($record, 'getUpdatedAtColumn') && ($column = $record->getUpdatedAtColumn()) !== null) {
            $stamp = $record->newQuery()->whereKey($record->getKey())->value($column);
            $seen = $this->autosaveSyncedAttributeHashes['__updated_at'] ?? null;

            if ($seen !== null && $this->autosaveStore()->snapshotHash(['v' => $stamp]) === $seen) {
                return false;
            }
        }

        $this->reloadAutosaveRecordAttributes($record);

        return $this->autosaveChangedRecordAttributes($record) !== [];
    }

    /**
     * Re-read the record's own columns in one query. Model::refresh() would
     * also reload every relation the form already hydrated, which is exactly
     * the per-poll cost this method exists to avoid.
     */
    protected function reloadAutosaveRecordAttributes(object $record): void
    {
        if (! method_exists($record, 'newQueryWithoutScopes') || ! method_exists($record, 'setRawAttributes')) {
            $record->refresh();

            return;
        }

        $fresh = $record->newQueryWithoutScopes()->whereKey($record->getKey())->first();

        if ($fresh === null) {
            return;
        }

        $record->setRawAttributes($fresh->getAttributes(), sync: true);
    }

    /**
     * Raw attributes whose value differs from what this component last saw.
     *
     * @return array<int, string>
     */
    protected function autosaveChangedRecordAttributes(object $record): array
    {
        $seen = $this->autosaveSyncedAttributeHashes;

        if ($seen === []) {
            return [];
        }

        $changed = [];

        foreach ($this->autosaveRecordAttributeHashes($record) as $attribute => $hash) {
            if ($attribute !== '__updated_at' && ($seen[$attribute] ?? null) !== $hash) {
                $changed[] = $attribute;
            }
        }

        return $changed;
    }

    protected function rememberAutosaveSyncedAttributes(object $record): void
    {
        $this->autosaveSyncedAttributeHashes = $this->autosaveRecordAttributeHashes($record);
    }

    /** @return array<string, string> */
    protected function autosaveRecordAttributeHashes(object $record): array
    {
        if (! method_exists($record, 'getAttributes')) {
            return [];
        }

        $hashes = [];

        foreach ($record->getAttributes() as $attribute => $value) {
            $hashes[(string) $attribute] = $this->autosaveStore()->snapshotHash(['v' => $value]);
        }

        if (method_exists($record, 'usesTimestamps') && $record->usesTimestamps()
            && method_exists($record, 'getUpdatedAtColumn') && ($column = $record->getUpdatedAtColumn()) !== null) {
            $hashes['__updated_at'] = $this->autosaveStore()->snapshotHash(['v' => $record->getAttributes()[$column] ?? null]);
        }

        return $hashes;
    }
}
