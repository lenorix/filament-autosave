<?php

namespace Lenorix\FilamentAutosave;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Resources\Events\RecordSaved;
use Filament\Resources\Events\RecordUpdated;
use Filament\Resources\Pages\Page;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\Relation;
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

    /**
     * Fingerprint of each polled relation as this component last observed it,
     * by top-level path. Depending on the configured mode this is a timestamp
     * summary, row-content hash, or host-provided token. Only kept while
     * `poll_relationships` is on.
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $autosaveSyncedRelationHashes = [];

    /**
     * Scalar descriptors for relation polling. Livewire carries these between
     * requests so an idle poll can rebuild relation queries without asking
     * Filament to flatten every nested Repeater again.
     *
     * @var array<string, array{kind: 'relation'|'media', refresh: string, name: string|null, pollPath?: string}>
     */
    #[Locked]
    public array $autosavePolledRelationDescriptors = [];

    /** @var array<string, string>|null Fingerprints already read this request; null once a write invalidated them. */
    protected ?array $autosaveReadRelationFingerprints = null;

    /** @var array<string, mixed> Clean columns re-read from the record for the status event. */
    protected array $autosaveRefreshState = [];

    /** @var array<string, array{order: array<int, string|int>, rows: array<string, string>}> Baselines for relationship rows. */
    #[Locked]
    public array $autosaveRelationshipRowHashes = [];

    /** @var array<string, array<int, string|int>> State keys touched in the current relationship write. */
    protected array $autosavePendingRelationshipRows = [];

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

    /** Set for the request whose render/dehydrate phase follows syncAutosave(). */
    protected bool $autosaveSyncRequest = false;

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
     * `RecordUpdated`/`RecordSaved` take a `Filament\Resources\Pages\Page`
     * in their constructor, so they are built only when this component is
     * one — an Edit page, or a custom resource page hosting its own form.
     * A relation manager, action or bare Livewire component is not, and
     * dispatches nothing: sending the class name with an array payload
     * instead (Filament's own `EditRecord::save()` idiom) never builds the
     * object and throws a `TypeError` inside any typed listener. Use the
     * package's own events there.
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
        }
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
        // This cycle writes: relation fingerprints read earlier in the request
        // no longer describe the database.
        $this->autosaveReadRelationFingerprints = null;
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
        $eligibleState = property_exists($this, 'data') && is_array($this->data)
            ? $this->data
            : $data;
        $eligiblePaths = array_fill_keys(array_map(strval(...), array_keys($eligibleState)), true);
        $data = $this->beforeAutosave($data);
        $data = $this->validateAutosaveFields($data);
        $data = $this->enforceFieldOptionRules($data);
        $this->syncAutosaveValidationErrors();

        if ($this->autosaveThrows && $this->autosaveValidationErrors !== []) {
            throw $this->autosaveValidationException();
        }

        // Filament's afterValidate hook only runs after a clean validation
        // pass. Autosave may still persist valid sibling fields, but a
        // validation error must not make the host observe a successful hook.
        $hasRelevantValidationErrors = false;

        foreach (array_keys($this->autosaveValidationErrors) as $key) {
            if (isset($eligiblePaths[AutosaveFieldTree::topLevelKey((string) $key)])) {
                $hasRelevantValidationErrors = true;

                break;
            }
        }

        if ($hasRelevantValidationErrors) {
            if ($this->autosaveHasNothingToPersist($data)) {
                $this->finishAutosaveWithoutWrite();

                return null;
            }

            return $data;
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
     * Run a record-backed cycle in one transaction, keeping the field hashes
     * and snapshot hash consistent with what actually reached the database.
     *
     * A failed or rolled-back cycle restores the hashes taken before it and
     * drops the Undo it prepared; a Halt that kept the transaction has
     * committed the write, so its hashes stand and only the report is quiet.
     */
    protected function runAutosaveCycleInTransaction(callable $cycle): mixed
    {
        $baseline = $this->captureAutosaveBaseline();
        $this->clearQueuedAutosaveNotification();

        try {
            $result = $this->autosaveWithinDatabaseTransaction($cycle);
            $this->afterAutosaveCycleCommitted();

            return $result;
        } catch (\Throwable $e) {
            // A failed commit must never leave a notification queued for a
            // later request.
            $this->clearQueuedAutosaveNotification();

            if ($this->autosaveHaltCommittedWrite()) {
                $this->afterAutosaveCycleHaltCommitted();

                throw $e;
            }

            $this->restoreAutosaveBaseline($baseline);
            $this->discardAutosaveCycleUndo();

            throw $e;
        }
    }

    /**
     * What a rolled-back cycle must put back: the acknowledged state the
     * dirty checks compare against. `HasAutosave` and `HasAutosaveForForm`
     * both keep per-field hashes on `$autosaveFieldHashes`, captured here
     * once rather than overridden identically in each.
     *
     * @return array<string, mixed>
     */
    protected function captureAutosaveBaseline(): array
    {
        $baseline = ['snapshotHash' => $this->autosaveSnapshotHash];

        if (property_exists($this, 'autosaveFieldHashes')) {
            $baseline['fieldHashes'] = $this->autosaveFieldHashes;
        }

        return $baseline;
    }

    /** @param  array<string, mixed>  $baseline */
    protected function restoreAutosaveBaseline(array $baseline): void
    {
        $this->autosaveSnapshotHash = $baseline['snapshotHash'];

        if (property_exists($this, 'autosaveFieldHashes') && array_key_exists('fieldHashes', $baseline)) {
            $this->autosaveFieldHashes = $baseline['fieldHashes'];
        }
    }

    /** The cycle's transaction committed normally. */
    protected function afterAutosaveCycleCommitted(): void
    {
        $this->flushAutosaveSavedNotification();
    }

    /** A Halt raised after the write kept the transaction: the write stands. */
    protected function afterAutosaveCycleHaltCommitted(): void {}

    /** The cycle rolled back: whatever Undo it prepared targets nothing. */
    protected function discardAutosaveCycleUndo(): void {}

    /**
     * Hooks, restores and events of one Undo, inside the caller's transaction.
     * Mirrors an explicit Filament save so hooks may halt or fail and roll
     * the restoration back.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, array<string, mixed>>  $relationshipSnapshot
     * @param  array<string, array<string, mixed>>  $externalSnapshot
     * @param  array<string, object>  $externalFields
     * @param  array<string, array<string, mixed>>  $expectedRelationships
     */
    protected function restoreAutosaveUndoParts(object $record, array $snapshot, array $relationshipSnapshot, array $externalSnapshot, array $externalFields, array $expectedRelationships = []): void
    {
        $this->callAutosaveHook('beforeValidate');
        $this->callAutosaveHook('afterValidate');
        $this->callAutosaveHook('beforeSave');

        if ($snapshot !== []) {
            $this->restoreAutosaveColumns($record, $snapshot);
        }

        if ($relationshipSnapshot !== []) {
            $this->restoreAutosaveRelationshipUndo($relationshipSnapshot, $expectedRelationships);
        }

        // External (file/media) restores come from the uploads trait, which
        // draft-only hosts do not carry.
        if ($externalSnapshot !== [] && method_exists($this, 'restoreAutosaveExternalUndo')) {
            $this->restoreAutosaveExternalUndo($externalSnapshot, $externalFields);
        }

        $this->callAutosaveHook('afterSave');
        $this->dispatchAutosaveRecordEvents($record, $snapshot);
    }

    /**
     * Write the column values of an Undo snapshot back to the record.
     *
     * @param  array<string, mixed>  $snapshot
     */
    protected function restoreAutosaveColumns(object $record, array $snapshot): void
    {
        $record->update($snapshot);
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
     * A lookup that may legitimately fail while a component mounts or renders
     * (no mounted action yet, no record yet). Not a failure, but a
     * misconfigured host is undiagnosable without a trace of it.
     */
    protected function autosaveLookupFailed(string $what, \Throwable $e): void
    {
        Log::debug("Autosave could not resolve {$what}", [
            'component' => static::class,
            'exception' => $e::class,
        ]);
    }

    /**
     * Surface a failed autosave operation without leaking field values.
     */
    protected function handleAutosaveFailure(\Throwable $e, string $context): void
    {
        $record = $this->autosaveEventRecord();

        // The message stays out on purpose: a database error quotes the
        // statement, values included.
        Log::warning("Autosave {$context} failed", [
            'component' => static::class,
            'record' => $record instanceof Model ? $record->getKey() : null,
            'exception' => $e::class,
        ]);

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
            } catch (\Throwable $e) {
                // A component may have no mounted action during mount/render.
                $this->autosaveLookupFailed('mounted action schema', $e);
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
            } catch (\Throwable $e) {
                // Some components only register action schemas lazily.
                $this->autosaveLookupFailed('form schema', $e);
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

    /** Capture one relationship component's per-row baseline. */
    protected function captureAutosaveRelationshipRowHashes(object $field): void
    {
        if (! method_exists($field, 'getRawState')) {
            return;
        }

        $path = $this->autosaveRelativeFieldPath($field);
        $state = $field->getRawState();

        if ($path === null || ! is_array($state) || ($state !== [] && count(array_filter($state, 'is_array')) !== count($state))) {
            return;
        }

        $rows = [];

        foreach ($state as $key => $row) {
            if (is_array($row)) {
                $rows[(string) $key] = $this->hashAutosaveValue($row);
            }
        }

        $this->autosaveRelationshipRowHashes[$path] = [
            'order' => array_keys($state),
            'rows' => $rows,
        ];
    }

    /**
     * Merge clean relationship rows from the database into a stale form
     * state, preserving only the rows this editor changed.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    protected function mergeAutosaveRelationshipRows(object $field, array $state): array
    {
        $path = $this->autosaveRelativeFieldPath($field);
        $baseline = $path !== null ? ($this->autosaveRelationshipRowHashes[$path] ?? null) : null;

        if ($path === null || $baseline === null || ! method_exists($field, 'getRelationship')) {
            return $state;
        }

        if ($state !== [] && count(array_filter($state, 'is_array')) !== count($state)) {
            return $state;
        }

        $currentRows = [];

        foreach ($state as $key => $row) {
            if (is_array($row)) {
                $currentRows[(string) $key] = $row;
            }
        }

        $dirty = array_fill_keys($this->autosaveRelationshipDirtyRowKeys($field, $state), true);
        $deleted = array_diff_key($dirty, $currentRows);
        $baselineOrder = array_map(strval(...), $baseline['order']);
        $stateOrder = array_map(strval(...), array_keys($state));
        $orderChanged = $baselineOrder !== $stateOrder;

        if ($dirty === [] && ! $orderChanged) {
            return $state;
        }

        // Undo must snapshot every row when membership/order changes, because
        // Filament rewrites the order column for each surviving row. Keep this
        // separate from `$dirty`, which contains content changes and explicit
        // additions/deletions only.
        $this->autosavePendingRelationshipRows[$path] = array_values(array_unique([
            ...array_keys($dirty),
            ...($orderChanged ? array_keys($baseline['rows']) : []),
        ]));

        try {
            $relationship = $field->getRelationship();
            $parent = method_exists($relationship, 'getParent') ? $relationship->getParent() : null;
            $name = method_exists($field, 'getRelationshipName') ? $field->getRelationshipName() : null;

            if ($parent !== null && filled($name) && method_exists($parent, 'unsetRelation')) {
                $parent->unsetRelation((string) $name);
            }

            if (method_exists($field, 'clearCachedExistingRecords')) {
                $field->clearCachedExistingRecords();
            }

            if (! method_exists($field, 'loadStateFromRelationships')) {
                return $state;
            }

            $field->loadStateFromRelationships(true);
            $remote = $field->getRawState();

            if (! is_array($remote)) {
                return $state;
            }

            $merged = $remote;

            foreach ($currentRows as $key => $row) {
                if (isset($dirty[$key])) {
                    $merged[$key] = $row;
                }
            }

            foreach (array_keys($deleted) as $key) {
                unset($merged[$key]);
            }

            if ($orderChanged) {
                $ordered = [];

                foreach (array_keys($state) as $key) {
                    if (array_key_exists($key, $merged)) {
                        $ordered[$key] = $merged[$key];
                    }
                }

                foreach ($merged as $key => $row) {
                    if (! array_key_exists($key, $ordered)) {
                        $ordered[$key] = $row;
                    }
                }

                $merged = $ordered;
            }

            return $merged;
        } catch (\Throwable) {
            // A failed refresh must never discard the user's local row.
            return $state;
        }
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<int, string>
     */
    protected function autosaveRelationshipDirtyRowKeys(object $field, array $state): array
    {
        $path = $this->autosaveRelativeFieldPath($field);
        $baseline = $path !== null ? ($this->autosaveRelationshipRowHashes[$path] ?? null) : null;

        if ($path === null || $baseline === null) {
            return [];
        }

        if ($state !== [] && count(array_filter($state, 'is_array')) !== count($state)) {
            return [];
        }

        $dirty = [];

        foreach ($state as $key => $row) {
            $key = (string) $key;

            if (! is_array($row) || ($baseline['rows'][$key] ?? null) !== $this->hashAutosaveValue($row)) {
                $dirty[] = $key;
            }
        }

        foreach (array_keys($baseline['rows']) as $key) {
            if (! array_key_exists($key, $state)) {
                $dirty[] = (string) $key;
            }
        }

        return array_values(array_unique($dirty));
    }

    /** @return array<int, string>|null Rows changed for a concrete component. */
    protected function autosavePendingRelationshipRowKeys(object $field): ?array
    {
        $path = $this->autosaveRelativeFieldPath($field);

        return $path !== null ? ($this->autosavePendingRelationshipRows[$path] ?? null) : null;
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

                // `relationship_undo_depth` has no fluent method on `AutosavePlugin`, config only, as documented in the README.
                if ($this->autosaveRelationshipUndoDepth($snapshotPath)
                    > max(1, (int) config('filament-autosave.relationship_undo_depth', 8))) {
                    $this->autosaveRelationshipUndoTruncated = true;

                    continue;
                }

                $captured = $this->captureAutosaveRelationshipUndoField($field);

                if ($captured !== null) {
                    $rowKeys = $this->autosavePendingRelationshipRowKeys($field);

                    if ($rowKeys === null && method_exists($field, 'getRawState') && is_array($state = $field->getRawState())) {
                        $rowKeys = $this->autosaveRelationshipDirtyRowKeys($field, $state);
                    }

                    if ($rowKeys !== null && $rowKeys !== []) {
                        $captured = $this->restrictAutosaveRelationshipUndoToRows($field, $captured, $rowKeys);
                    }

                    $snapshot[$snapshotPath] = $captured;
                }
            }
        }

        return $snapshot;
    }

    /**
     * Keep relationship Undo scoped to the repeater rows changed by this
     * cycle. The full shape remains backwards compatible for older snapshots.
     *
     * @param  array<string, mixed>  $captured
     * @param  array<int, string|int>  $rowKeys
     * @return array<string, mixed>
     */
    protected function restrictAutosaveRelationshipUndoToRows(object $field, array $captured, array $rowKeys): array
    {
        if (! isset($captured['rows']) || ! is_array($captured['rows'])) {
            return $captured;
        }

        // New repeater rows do not have a database key until after the write.
        // Keep the established full snapshot for those cycles; it remains
        // safe and lets Undo remove newly-created rows correctly.
        if (array_filter($rowKeys, static fn (string|int $rowKey): bool => ! str_starts_with((string) $rowKey, 'record-')) !== []) {
            return $captured;
        }

        $ids = [];

        foreach ($rowKeys as $rowKey) {
            $rowKey = (string) $rowKey;

            if (str_starts_with($rowKey, 'record-')) {
                $ids[] = substr($rowKey, 7);
            }
        }

        $captured['partial'] = true;
        $captured['stateKeys'] = array_values(array_map(strval(...), $rowKeys));
        $captured['rows'] = array_values(array_filter(
            $captured['rows'],
            function (array $row) use ($ids): bool {
                $key = $row['key'] ?? ($row['attributes']['id'] ?? null);

                return $key !== null && in_array((string) $key, $ids, true);
            },
        ));

        return $captured;
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
            $pivot = $related->relationLoaded('pivot') ? $related->getRelation('pivot') : null;

            $rows[] = [
                'key' => $related->getKey(),
                'pivot' => $pivot instanceof Model ? $pivot->getAttributes() : [],
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

    /**
     * @param  array<string, array<string, mixed>>  $snapshot
     * @param  array<string, array<string, mixed>>  $expected
     */
    protected function restoreAutosaveRelationshipUndo(array $snapshot, array $expected = []): void
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
                $relation instanceof BelongsToMany => $this->restoreBelongsToManyUndo($relation, $state['rows'] ?? [], $state['partial'] ?? false, $expected[$path]['rows'] ?? []),
                $relation instanceof HasOneOrManyThrough => $this->restoreHasManyThroughUndo($relation, $state['rows'] ?? [], $state['partial'] ?? false, $expected[$path]['rows'] ?? []),
                $relation instanceof HasOneOrMany => $this->restoreHasManyUndo($relation, $state['rows'] ?? [], $state['partial'] ?? false, $expected[$path]['rows'] ?? []),
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
     * @param  array<int, array<string, mixed>>  $expectedRows
     */
    protected function restoreBelongsToManyUndo(BelongsToMany $relation, array $rows, bool $partial = false, array $expectedRows = []): void
    {
        $ids = [];

        foreach ($rows as $row) {
            $ids[$row['key']] = $row['pivot'] ?? [];
        }

        if ($partial) {
            $expected = [];

            foreach ($expectedRows as $row) {
                $expected[(string) $row['key']] = true;
            }

            $current = $relation->get()->keyBy(fn (Model $model): string => (string) $model->getKey());
            $touched = array_unique([...array_keys($ids), ...array_keys($expected)]);

            foreach ($touched as $key) {
                if (array_key_exists((string) $key, $ids)) {
                    $relation->syncWithoutDetaching([(string) $key => $ids[(string) $key]]);
                } elseif ($current->has((string) $key)) {
                    $relation->detach($key);
                }
            }

            return;
        }

        $relation->sync($ids);
    }

    /**
     * @param  HasOneOrManyThrough<Model, Model, Model, mixed>  $relation
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $expectedRows
     */
    protected function restoreHasManyThroughUndo(HasOneOrManyThrough $relation, array $rows, bool $partial = false, array $expectedRows = []): void
    {
        $related = $relation->getRelated();
        $keyName = $related->getKeyName();
        $original = $this->autosaveRelatedRowsByKey($rows, $keyName);

        if ($partial) {
            $expected = $this->autosaveRelatedRowsByKey($expectedRows, $keyName);
            $existing = $this->autosaveCurrentRelatedRows($relation);

            foreach (array_diff_key($expected, $original) as $key => $attributes) {
                $existing->get($key)?->delete();
            }

            foreach ($original as $attributes) {
                $this->restoreAutosaveRelatedModel($related, $attributes, $keyName, $existing)->save();
            }

            return;
        }

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
     * @param  array<int, array<string, mixed>>  $expectedRows
     */
    protected function restoreHasManyUndo(HasOneOrMany $relation, array $rows, bool $partial = false, array $expectedRows = []): void
    {
        $related = $relation->getRelated();
        $keyName = $related->getKeyName();
        $original = $this->autosaveRelatedRowsByKey($rows, $keyName);

        if ($partial) {
            $expected = $this->autosaveRelatedRowsByKey($expectedRows, $keyName);
            $existing = $this->autosaveCurrentRelatedRows($relation);

            foreach (array_diff_key($expected, $original) as $key => $attributes) {
                $existing->get($key)?->delete();
            }

            foreach ($original as $attributes) {
                $relation->save($this->restoreAutosaveRelatedModel($related, $attributes, $keyName, $existing));
            }

            return;
        }

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

        // Polling returns its state through the autosave-status event. Avoid a
        // full Filament render after every idle tick; nested Repeaters would
        // otherwise hydrate their relationship components once per row after
        // the detector has already batched them.
        if (method_exists($this, 'skipRender')) {
            $this->skipRender();
        }

        $this->autosaveSyncRequest = true;

        $record = $this->autosaveSyncRecord();

        if (! is_object($record) || ! method_exists($record, 'getAttributes') || ! ($record->exists ?? false)) {
            return;
        }

        $this->authorizeAutosaveAccess();

        try {
            // Relations first: their detector is one query whatever the form,
            // and a column check that finds nothing must not end the poll
            // while a child row changed underneath.
            $relations = $this->autosaveChangedRelationPaths($record);
            $columnsChanged = $this->autosaveRecordChangedRemotely($record);

            if (! $columnsChanged && $relations === []) {
                return;
            }

            $changed = $columnsChanged ? $this->autosaveChangedRecordAttributes($record) : [];

            if ($changed === [] && $relations === []) {
                $this->rememberAutosaveSyncedAttributes($record);

                return;
            }

            // Dehydrating the form is the expensive part of a poll (a query
            // per relationship field), so only when a column changed or a
            // relation's cleanliness cannot be judged without it.
            $current = $changed !== [] || $this->autosaveRelationsNeedPayload($relations)
                ? $this->autosaveDehydrateForPoll()
                : [];

            $plan = $changed === []
                ? ['refill' => [], 'stale' => [], 'patches' => []]
                : AutosaveSync::plan(
                    $changed,
                    $current,
                    $this->autosaveRefreshablePaths($record, $current, polling: true),
                    $this->autosaveMergeablePaths(),
                    array_filter($mergeBaseHashes, 'is_string'),
                    $record->getAttributes(),
                    $this->autosaveFieldIsClean(...),
                    $this->autosavePathExcluded(...),
                    fn (string $path, mixed $raw): mixed => $this->autosaveMergeRemoteValue($record, $path, $raw),
                );

            $refreshed = $this->refillAutosavePaths($record, $plan['refill']);
            $relationPlan = $this->refillAutosaveRelationPaths($record, $relations, $current);
            $refreshed = [...$refreshed, ...$relationPlan['refreshed']];
            $stale = $plan['stale'];

            foreach ($relationPlan['stale'] as $path) {
                if (! in_array($path, $stale, true)) {
                    $stale[] = $path;
                }
            }

            sort($stale);
            // Only now: a failed refill must leave the change visible to the
            // next poll, or the timestamp fast path would hide it for good.
            $this->rememberAutosaveSyncedAttributes($record);
        } catch (\Throwable $e) {
            $this->handleAutosaveFailure($e, 'sync');

            return;
        }

        if ($refreshed === [] && $stale === []) {
            return;
        }

        $this->dispatchAutosaveStatus(AutosaveStatus::Synced, AutosaveSync::syncedPayload($refreshed, $stale, $plan['patches']));
        Event::dispatch(new AutosaveSynced($this, $record, $refreshed, $stale, $plan['patches']));
    }

    /**
     * The live form's prepared payload, for the poll's eligibility checks.
     *
     * @return array<string, mixed>
     */
    protected function autosaveDehydrateForPoll(): array
    {
        $this->autosaveFieldsCache = null;

        return $this->prepareAutosavePayload($this->getAutosaveData());
    }

    /**
     * Whether judging these relations clean needs the dehydrated payload. A
     * trait that keeps relationship hashes (Edit pages) answers from those;
     * a media field answers from its upload hash.
     *
     * @param  array<string, bool>  $relations
     */
    protected function autosaveRelationsNeedPayload(array $relations): bool
    {
        if ($relations === []) {
            return false;
        }

        if (! property_exists($this, 'autosaveRelationshipHashes') || ! method_exists($this, 'autosaveRelationshipHash')) {
            return true;
        }

        return false;
    }

    /**
     * Whether polls also refresh clean relationship, upload and media fields.
     * Off whenever polling itself is, and never for drafts.
     */
    protected function autosavePollsRelationships(): bool
    {
        return $this->autosaveRefreshEnabled() && AutosavePlugin::resolve()->getPollRelationships();
    }

    /**
     * Top-level fields a poll may refresh from a relation, by path: repeaters,
     * selects and checkbox lists bound to a real relation (`relation`), and
     * Spatie media fields, which hang off the record's `media()` relation
     * (`media`). Not a RichEditor nor a BelongsTo select (both are columns),
     * and not excluded. Nested relationship fields use their concrete row path
     * and refresh independently up to the configured depth.
     *
     * @return array<string, array{kind: 'relation'|'media', components: array<int, object>, relation: Relation<Model, Model, *>, refresh: string, name: string|null}>
     */
    protected function autosavePolledRelationFields(object $record, bool $withComponents = true): array
    {
        if (! $this->autosavePollsRelationships()) {
            return [];
        }

        if (! $withComponents && $this->autosavePolledRelationDescriptors !== []) {
            return $this->buildAutosavePolledRelationFields($record);
        }

        $polled = [];

        if (method_exists($this, 'autosaveRelationshipFields')) {
            foreach ($this->autosaveRelationshipFields() as $path => $fields) {
                $path = (string) $path;
                $refresh = AutosaveFieldTree::topLevelKey($path);

                foreach ($fields as $field) {
                    $relation = method_exists($field, 'getRelationship') ? $field->getRelationship() : null;

                    if (! $relation instanceof Relation || $relation instanceof BelongsTo || $field instanceof RichEditor) {
                        continue;
                    }

                    $concretePath = $this->autosaveRelativeFieldPath($field) ?? $path;

                    if ($this->autosavePathExcluded($concretePath)
                        || str_contains($concretePath, '*')) {
                        continue;
                    }

                    $key = $concretePath === $path ? $path : $concretePath;
                    $name = method_exists($field, 'getRelationshipName')
                        ? $field->getRelationshipName()
                        : null;
                    $polled[$key] ??= [
                        'kind' => 'relation',
                        'components' => [],
                        'relation' => $relation,
                        'refresh' => $refresh,
                        'name' => is_string($name) ? $name : null,
                    ];

                    if ($withComponents) {
                        $polled[$key]['components'][] = $field;
                    }
                }
            }
        }

        if (method_exists($this, 'autosaveUploadFields') && method_exists($record, 'media')) {
            foreach ($this->autosaveUploadFields() as $path => $field) {
                $path = (string) $path;

                if (! $field instanceof SpatieMediaLibraryFileUpload || str_contains($path, '*')
                    || $this->autosavePathExcluded($path) || isset($polled[$path])) {
                    continue;
                }

                $relation = $record->media();

                if ($relation instanceof Relation) {
                    $polled[$path] = [
                        'kind' => 'media',
                        'components' => [$field],
                        'relation' => $relation,
                        'refresh' => $path,
                        'name' => 'media',
                    ];
                }
            }
        }

        // A relationship can live below a non-relationship container (for
        // example `settings.items`). Keep the component's original key for
        // refreshes, but store a normalized path for rebuilding its query
        // graph on a later polling request.
        $relationNames = array_values(array_unique(array_filter(array_map(
            static fn (array $entry): ?string => is_string($entry['name'] ?? null) ? $entry['name'] : null,
            $polled,
        ))));

        $this->autosavePolledRelationDescriptors = [];

        foreach ($polled as $path => $entry) {
            $pollPath = $entry['kind'] === 'relation'
                ? $this->autosaveNormalizePolledRelationPath((string) $path, $relationNames)
                : $entry['refresh'];

            if ($entry['kind'] === 'relation'
                && $this->autosaveRelationPathDepth($pollPath) > $this->autosavePollRelationshipDepth()) {
                unset($polled[$path]);

                continue;
            }

            $this->autosavePolledRelationDescriptors[$path] = [
                'kind' => $entry['kind'],
                'refresh' => $entry['refresh'],
                'name' => $entry['name'],
                'pollPath' => $pollPath,
            ];
        }

        return $polled;
    }

    /**
     * Rebuild poll entries from scalar descriptors carried by Livewire.
     * Resolving all parents level by level lets Eloquent eager-load one query
     * per rendered relationship level, including a self-referential
     * `children.children` schema.
     *
     * @return array<string, array{kind: 'relation'|'media', components: array<int, object>, relation: Relation<Model, Model, *>, refresh: string, name: string|null}>
     */
    protected function buildAutosavePolledRelationFields(object $record): array
    {
        $polled = [];
        $relationDescriptors = [];

        foreach ($this->autosavePolledRelationDescriptors as $path => $descriptor) {
            if ($descriptor['kind'] === 'media') {
                if (method_exists($record, 'media') && ($relation = $record->media()) instanceof Relation) {
                    $polled[$path] = [
                        'kind' => 'media',
                        'components' => [],
                        'relation' => $relation,
                        'refresh' => $descriptor['refresh'],
                        'name' => 'media',
                    ];
                }

                continue;
            }

            if (! is_string($descriptor['name'] ?? null)) {
                continue;
            }

            $relationDescriptors[(string) $path] = $descriptor;
        }

        if ($relationDescriptors === []) {
            return $polled;
        }

        /** @var array<string, Model> $parents */
        $parents = ['' => $record instanceof Model ? $record : null];
        $maxLevels = 0;

        foreach ($relationDescriptors as $descriptor) {
            $pollPath = (string) ($descriptor['pollPath'] ?? '');
            $maxLevels = max($maxLevels, (int) ceil(count(explode('.', $pollPath)) / 2));
        }

        for ($level = 0; $level < $maxLevels; $level++) {
            $requests = [];

            foreach ($relationDescriptors as $path => $descriptor) {
                $pollPath = (string) ($descriptor['pollPath'] ?? $path);
                $segments = explode('.', $pollPath);
                $relationIndex = $level * 2;

                if (! isset($segments[$relationIndex])) {
                    continue;
                }

                $parentPath = implode('.', array_slice($segments, 0, $relationIndex));
                $parent = $parents[$parentPath] ?? null;

                if (! $parent instanceof Model || ! $parent->exists) {
                    continue;
                }

                $name = (string) $segments[$relationIndex];
                $requests[] = [
                    'path' => $path,
                    'pollPath' => $pollPath,
                    'parentPath' => $parentPath,
                    'parent' => $parent,
                    'name' => $name,
                    'final' => ! isset($segments[$relationIndex + 2]),
                    'rowKey' => $segments[$relationIndex + 1] ?? null,
                ];
            }

            if ($requests === []) {
                continue;
            }

            $groups = [];

            foreach ($requests as $request) {
                if ($request['final']) {
                    continue;
                }

                $groupKey = $request['parent']::class.'|'.$request['name'];
                $groups[$groupKey]['name'] = $request['name'];
                $groups[$groupKey]['parents'][(string) $request['parent']->getKey()] = $request['parent'];
            }

            foreach ($groups as $group) {
                $groupParents = array_values($group['parents']);
                $collection = $groupParents[0]->newCollection($groupParents);

                foreach ($groupParents as $parent) {
                    $parent->unsetRelation($group['name']);
                }

                $collection->load($group['name']);
            }

            foreach ($requests as $request) {
                $parent = $request['parent'];
                $name = $request['name'];
                $relation = $parent->{$name}();

                if (! $relation instanceof Relation) {
                    continue;
                }

                if ($request['final']) {
                    $descriptor = $relationDescriptors[$request['path']];
                    $polled[$request['path']] = [
                        'kind' => 'relation',
                        'components' => [],
                        'relation' => $relation,
                        'refresh' => $descriptor['refresh'],
                        'name' => (string) $descriptor['name'],
                    ];

                    continue;
                }

                $related = $parent->getRelation($name);
                $row = $this->autosavePollRelatedRow($related, (string) $request['rowKey']);

                if ($row instanceof Model) {
                    $rowPath = $request['parentPath'] === ''
                        ? $name.'.'.$request['rowKey']
                        : $request['parentPath'].'.'.$name.'.'.$request['rowKey'];
                    $parents[$rowPath] = $row;
                }
            }
        }

        return $polled;
    }

    protected function autosavePollRelatedRow(mixed $related, string $rowKey): ?Model
    {
        if ($related instanceof Model) {
            return (string) $related->getKey() === $rowKey || 'record-'.$related->getKey() === $rowKey
                ? $related
                : null;
        }

        if (! is_iterable($related)) {
            return null;
        }

        foreach ($related as $row) {
            if ($row instanceof Model
                && ((string) $row->getKey() === $rowKey || 'record-'.$row->getKey() === $rowKey)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Remove state-only container segments before the first relation name.
     *
     * @param  list<string>  $relationNames
     */
    protected function autosaveNormalizePolledRelationPath(string $path, array $relationNames): string
    {
        $segments = array_values(array_filter(explode('.', $path), static fn (string $segment): bool => $segment !== ''));

        foreach ($segments as $index => $segment) {
            if (in_array($segment, $relationNames, true)) {
                return implode('.', array_slice($segments, $index));
            }
        }

        return $path;
    }

    /** Count relationship segments in a concrete form path (`items.id.children` => 2). */
    protected function autosaveRelationPathDepth(string $path): int
    {
        $segments = array_values(array_filter(explode('.', $path), static fn (string $segment): bool => $segment !== ''));

        return (int) ceil(count($segments) / 2);
    }

    protected function autosavePollRelationshipDepth(): int
    {
        return max(1, (int) config('filament-autosave.poll_relationship_depth', 3));
    }

    protected function autosavePollRelationshipRowLimit(): int
    {
        return max(1, (int) config('filament-autosave.poll_relationship_max_rows', 500));
    }

    /**
     * Load nested relations in batches. A concrete Repeater
     * row owns its own Relation instance, but all rows at one schema level
     * share the same parent class and relationship name. Eloquent can eager
     * load that set in one query, avoiding one detector query per rendered
     * child. Unsaved local rows are skipped: they have no database identity
     * that a poll can inspect.
     *
     * @param  array<string, array{kind: 'relation'|'media', components: array<int, object>, relation: Relation<Model, Model, *>, refresh: string, name: string|null}>  $fields
     * @param  bool  $timestampFreeOnly  Limit batching to relations whose rows have no update stamp.
     * @param  bool  $timestampedOnly  Limit batching to relations whose rows have an update stamp.
     * @return array<string, array<int, Model>>
     */
    protected function preloadAutosavePolledRelations(
        array $fields,
        bool $timestampFreeOnly = true,
        bool $timestampedOnly = false,
    ): array {
        $groups = [];

        foreach ($fields as $path => $entry) {
            if ($entry['kind'] !== 'relation' || ! is_string($entry['name'])) {
                continue;
            }

            // Top-level fields already have one relation query and must keep
            // the row-limit probe for large timestamp-free collections.
            if ($this->autosaveRelationPathDepth($path) <= 1) {
                continue;
            }

            $relation = $entry['relation'];

            $hasTimestamp = $this->autosaveRelationStampColumn($relation) !== null;

            if (($timestampFreeOnly && $hasTimestamp)
                || ($timestampedOnly && ! $hasTimestamp)
                || ! method_exists($relation, 'getParent')) {
                continue;
            }

            $parent = $relation->getParent();

            if (! $parent->exists || $parent->getKey() === null) {
                continue;
            }

            $name = $entry['name'];
            $groupKey = $parent::class.'|'.$name;
            $groups[$groupKey]['name'] = $name;
            $groups[$groupKey]['relation'] ??= $relation;
            $groups[$groupKey]['parents'][(string) $parent->getKey()] = $parent;
            $groups[$groupKey]['paths'][] = $path;
        }

        $preloaded = [];

        foreach ($groups as $group) {
            $parents = array_values($group['parents']);

            $parentsCollection = $parents[0]->newCollection($parents);

            // A relation may have been hydrated while Filament rebuilt the
            // nested schema. Drop that value before eager loading so a poll
            // sees remote inserts and edits as well as the initial snapshot.
            foreach ($parents as $parent) {
                $parent->unsetRelation($group['name']);
            }

            if ($timestampFreeOnly && method_exists($group['relation'], 'getExistenceCompareKey')) {
                $limit = $this->autosavePollRelationshipRowLimit() + 1;
                $relation = $group['relation'];

                $parentsCollection->load([
                    $group['name'] => function ($query) use ($relation, $limit): void {
                        $query->getQuery()->groupLimit($limit, $relation->getExistenceCompareKey());
                    },
                ]);
            } else {
                $parentsCollection->load($group['name']);
            }

            foreach ($group['paths'] as $path) {
                $relation = $fields[$path]['relation'];

                if (! method_exists($relation, 'getParent')) {
                    continue;
                }

                $parent = $relation->getParent();
                $name = $fields[$path]['name'];

                if (! is_string($name) || ! $parent->relationLoaded($name)) {
                    continue;
                }

                $loaded = $parent->getRelation($name);
                $preloaded[$path] = $loaded instanceof Model
                    ? [$loaded]
                    : (is_iterable($loaded) ? iterator_to_array($loaded, false) : []);
            }
        }

        return $preloaded;
    }

    /**
     * One union query for all timestamped polled relations: it returns the
     * related key and update stamp for each row, compared against what the
     * last poll saw. Keys are cast to text by the active grammar and hashed in
     * PHP, so UUID and string keys never pass through numeric SQL aggregates.
     * Relations without timestamps use the configured bounded, exact, or
     * conservative fingerprint mode. Hosts can provide a stable token with
     * getAutosavePollFingerprint().
     *
     * @return array{fingerprints: array<string, string>, unfingerprinted: list<string>, uncertain: list<string>}
     */
    protected function autosaveRelationFingerprints(object $record): array
    {
        $fields = $this->autosavePolledRelationFields($record, withComponents: false);

        if ($fields === [] || ! $record instanceof Model) {
            return ['fingerprints' => [], 'unfingerprinted' => [], 'uncertain' => []];
        }

        $union = null;
        $fingerprints = [];
        $unfingerprinted = [];
        $uncertain = [];
        $keyRows = [];
        // Exact mode reads each timestamp-free relation in full below. Avoid
        // first issuing the bounded nested preload, which would only be
        // discarded and would double the detector cost for deep forms.
        $preloaded = $this->autosavePollRelationshipFingerprintMode() === 'exact'
            ? []
            : $this->preloadAutosavePolledRelations($fields);
        $preloaded = [...$preloaded, ...$this->preloadAutosavePolledRelations(
            $fields,
            timestampFreeOnly: false,
            timestampedOnly: true,
        )];

        foreach ($fields as $path => ['relation' => $relation]) {
            $custom = $this->getAutosavePollFingerprint((string) $path, $relation);

            if ($custom !== null) {
                $fingerprints[$path] = $this->autosaveStore()->snapshotHash(['custom' => $custom]);

                continue;
            }

            $stamp = $this->autosaveRelationStampColumn($relation);

            if ($stamp === null) {
                $mode = $this->autosavePollRelationshipFingerprintMode();

                if ($mode === 'exact') {
                    $fingerprints[$path] = $this->autosaveTimestampFreeRelationFingerprintFromRows(
                        (clone $relation)->get(),
                    );

                    continue;
                }

                // Without timestamps, compare persisted contents rather than
                // silently ignoring remote changes while the field is dirty.
                $rows = $preloaded[$path] ?? (clone $relation)->limit($this->autosavePollRelationshipRowLimit() + 1)->get();

                $rowCount = is_array($rows) ? count($rows) : $rows->count();

                if ($rowCount > $this->autosavePollRelationshipRowLimit()) {
                    if ($mode === 'conservative') {
                        $uncertain[] = (string) $path;

                        continue;
                    }

                    $query = (clone $relation->getQuery())->toBase();
                    $query->columns = null;
                    $query->orders = null;
                    $query->limit = null;
                    $query->offset = null;
                    $grammar = $query->getGrammar();
                    $key = $grammar->wrap($this->autosaveRelationKeyColumn($relation));
                    $aggregate = $query->selectRaw("count(*) as autosave_count, min({$key}) as autosave_min_key, max({$key}) as autosave_max_key")->first();
                    $fingerprints[$path] = $this->autosaveStore()->snapshotHash([
                        'overflow' => true,
                        'count' => (int) ($aggregate->autosave_count ?? 0),
                        'min' => $aggregate->autosave_min_key ?? null,
                        'max' => $aggregate->autosave_max_key ?? null,
                    ]);

                    continue;
                }

                $fingerprints[$path] = $this->autosaveTimestampFreeRelationFingerprintFromRows($rows);

                continue;
            }

            if (array_key_exists($path, $preloaded)) {
                $fingerprints[$path] = $this->autosaveRelationFingerprintFromRows($relation, $preloaded[$path]);

                continue;
            }

            $query = (clone $relation->getQuery())->toBase();
            $query->columns = null;
            $query->orders = null;
            $query->limit = null;
            $query->offset = null;
            // A quoted literal, not a binding: Postgres cannot type a bare
            // parameter in a select list. Cast key and stamp values to text
            // before unioning, because PostgreSQL requires every UNION column
            // to have one type even when two relations use different keys.
            $grammar = $query->getGrammar();
            $key = $grammar->wrap($this->autosaveRelationKeyColumn($relation));
            $grammarClass = strtolower($grammar::class);
            $driver = str_contains($grammarClass, 'postgres')
                ? 'pgsql'
                : (str_contains($grammarClass, 'mysql') ? 'mysql' : 'sqlite');
            $keyText = $this->autosaveRelationFingerprintText($key, $driver);
            $stampText = $this->autosaveRelationFingerprintText($grammar->wrap($stamp), $driver);
            $keyRows[$path] = [];
            $query->selectRaw(
                $grammar->quoteString($path).' as autosave_path, '.$keyText.' as autosave_key, '.$stampText.' as autosave_stamp',
            );

            $union = $union === null ? $query : $union->unionAll($query);
        }

        if ($union !== null) {
            foreach ($union->get() as $row) {
                $row = (array) $row;
                $path = (string) $row['autosave_path'];

                if (! array_key_exists($path, $keyRows)) {
                    continue;
                }

                $keyRows[$path][] = $row['autosave_key'] === null ? null : (string) $row['autosave_key'];
                $stamp = $row['autosave_stamp'] === null ? null : (string) $row['autosave_stamp'];

                if ($stamp !== null) {
                    $keyRows[$path]['__stamps'][] = $stamp;
                }
            }
        }

        foreach ($keyRows as $path => $keys) {
            $stamps = $keys['__stamps'] ?? [];
            unset($keys['__stamps']);
            $keys = array_values(array_filter($keys, static fn (mixed $key): bool => $key !== null));
            sort($keys, SORT_STRING);
            sort($stamps, SORT_STRING);

            $fingerprints[$path] = $this->autosaveStore()->snapshotHash([
                'keys' => $keys,
                'stamp' => $stamps === [] ? null : $stamps[array_key_last($stamps)],
            ]);
        }

        return ['fingerprints' => $fingerprints, 'unfingerprinted' => $unfingerprinted, 'uncertain' => $uncertain];
    }

    /**
     * Build an exact content fingerprint for timestamp-free rows, including
     * primary keys and pivot attributes so replacements and pivot edits are
     * distinguishable even when the row count stays the same.
     *
     * @param  iterable<int, mixed>  $rows
     */
    protected function autosaveTimestampFreeRelationFingerprintFromRows(iterable $rows): string
    {
        $rowHashes = [];

        foreach ($rows as $row) {
            if (! $row instanceof Model) {
                continue;
            }

            $state = $row->getAttributes();
            unset($state['laravel_through_key']);
            ksort($state);

            $pivots = [];

            foreach ($row->getRelations() as $name => $related) {
                if ($related instanceof Pivot) {
                    $pivots[$name] = $related->getAttributes();
                    ksort($pivots[$name]);
                }
            }

            ksort($pivots);
            $rowHashes[] = $this->autosaveStore()->snapshotHash([
                'key' => (string) $row->getKey(),
                'attributes' => $state,
                'pivots' => $pivots,
            ]);
        }

        sort($rowHashes, SORT_STRING);

        return $this->autosaveStore()->snapshotHash(['rows' => $rowHashes]);
    }

    /**
     * Return a host-provided stable token for a polled relationship. A token
     * can be a parent revision, an aggregate version, or another value that
     * changes whenever the relation's persisted state changes.
     *
     * @api
     *
     * @param  Relation<Model, Model, *>  $relation
     */
    protected function getAutosavePollFingerprint(string $path, Relation $relation): ?string
    {
        return null;
    }

    protected function autosavePollRelationshipFingerprintMode(): string
    {
        $mode = AutosavePlugin::resolve()->getPollRelationshipFingerprintMode();

        return in_array($mode, ['bounded', 'exact', 'conservative'], true) ? $mode : 'bounded';
    }

    /**
     * Fingerprint rows already eager-loaded for a nested timestamped relation.
     * This keeps the detector independent from the number of rendered child
     * rows while preserving the same key/stamp contract as the SQL path.
     *
     * @param  Relation<Model, Model, *>  $relation
     * @param  iterable<int, Model>  $rows
     */
    protected function autosaveRelationFingerprintFromRows(Relation $relation, iterable $rows): string
    {
        $keys = [];
        $stamps = [];

        foreach ($rows as $row) {
            $keys[] = (string) $row->getKey();

            if ($relation instanceof BelongsToMany) {
                $value = $row->getRelationValue('pivot')?->getAttribute($relation->updatedAt());
            } else {
                $value = $row->getAttribute($row->getUpdatedAtColumn());
            }

            if ($value !== null) {
                $stamps[] = (string) $value;
            }
        }

        sort($keys, SORT_STRING);
        sort($stamps, SORT_STRING);

        return $this->autosaveStore()->snapshotHash([
            'keys' => $keys,
            'stamp' => $stamps === [] ? null : $stamps[array_key_last($stamps)],
        ]);
    }

    /** The related row's key (the related key on a pivot), qualified.
     *
     * @param  Relation<Model, Model, *>  $relation
     */
    protected function autosaveRelationKeyColumn(Relation $relation): string
    {
        if ($relation instanceof BelongsToMany) {
            return $relation->getQualifiedRelatedPivotKeyName();
        }

        return $relation->getRelated()->getQualifiedKeyName();
    }

    /**
     * Convert a qualified key or timestamp to a common SQL text type before
     * it is used in a UNION. The cast syntax is the small portable part that
     * differs between the supported database grammars; the fingerprinting
     * itself stays database-independent in PHP.
     */
    protected function autosaveRelationFingerprintText(string $column, string $driver): string
    {
        return match ($driver) {
            'mysql', 'mariadb' => 'CAST('.$column.' AS CHAR)',
            'pgsql' => 'CAST('.$column.' AS TEXT)',
            default => 'CAST('.$column.' AS TEXT)',
        };
    }

    /** The column whose maximum tells a row edit apart, or null when the relation has no timestamps.
     *
     * @param  Relation<Model, Model, *>  $relation
     */
    protected function autosaveRelationStampColumn(Relation $relation): ?string
    {
        if ($relation instanceof BelongsToMany) {
            $column = $relation->updatedAt();

            return $relation->hasPivotColumn($column) ? $relation->qualifyPivotColumn($column) : null;
        }

        $related = $relation->getRelated();

        return $related->usesTimestamps() && $related->getUpdatedAtColumn() !== null
            ? $related->getQualifiedUpdatedAtColumn()
            : null;
    }

    /**
     * Polled relation paths that may have changed since the last poll: those
     * whose fingerprint moved (`true`, a certain change) plus every relation
     * that has none (`false`: re-read and compared, never reported stale).
     *
     * @return array<string, bool>
     */
    protected function autosaveChangedRelationPaths(object $record): array
    {
        if (! $this->autosavePollsRelationships()) {
            return [];
        }

        ['fingerprints' => $fingerprints, 'unfingerprinted' => $unfingerprinted, 'uncertain' => $uncertain] = $this->autosaveRelationFingerprints($record);
        $this->autosaveReadRelationFingerprints = $fingerprints;
        $changed = array_fill_keys($unfingerprinted, false);

        foreach ($uncertain as $path) {
            $changed[$path] = true;
        }

        foreach ($fingerprints as $path => $hash) {
            if (($this->autosaveSyncedRelationHashes[$path] ?? null) !== $hash) {
                $changed[$path] = true;
            }
        }

        return $changed;
    }

    /**
     * Re-read the given relations through their components, exactly as on
     * page load, when this user has not touched them; report the rest as
     * stale. Nothing is written and Undo is untouched.
     *
     * @param  array<string, bool>  $paths  Path => whether the remote change is certain.
     * @param  array<string, mixed>  $current  Prepared payload of the live form.
     * @return array{refreshed: array<string, mixed>, stale: list<string>}
     */
    protected function refillAutosaveRelationPaths(object $record, array $paths, array $current): array
    {
        if ($paths === []) {
            return ['refreshed' => [], 'stale' => []];
        }

        $fields = $this->autosavePolledRelationFields($record);
        $refreshed = [];
        $stale = [];
        $refilled = [];
        $cleanEntries = [];

        foreach ($paths as $path => $certain) {
            $entry = $fields[$path] ?? null;

            if ($entry === null) {
                continue;
            }

            ['kind' => $kind, 'components' => $components, 'refresh' => $refresh] = $entry;

            if (! $this->autosaveRelationFieldIsClean($kind, $path, $components, $current)) {
                if ($certain) {
                    $stale[] = $refresh;
                }

                continue;
            }

            $cleanEntries[$path] = $entry;
        }

        // Load all clean nested parents for one relation level together. The
        // components still refresh individually, but their relation getters
        // now read the eager-loaded value instead of issuing one query each.
        $preloaded = $this->preloadAutosavePolledRelations($cleanEntries, timestampFreeOnly: false);

        foreach ($cleanEntries as $path => $entry) {
            ['kind' => $kind, 'components' => $components] = $entry;

            $before = $this->autosaveRelationStateHash($components);

            foreach ($components as $component) {
                $name = method_exists($component, 'getRelationshipName')
                    ? $component->getRelationshipName()
                    : null;

                if (! array_key_exists($path, $preloaded)
                    && is_string($name) && method_exists($record, 'unsetRelation')) {
                    $record->unsetRelation((string) $component->getRelationshipName());
                }

                if (method_exists($component, 'clearCachedExistingRecords')) {
                    $component->clearCachedExistingRecords();
                } elseif (method_exists($component, 'state')) {
                    // A non-Repeater relationship field (Select, CheckboxList)
                    // fills its state from the relationship only once, while
                    // its state is still empty: Filament's own
                    // fillStateFromRelationship() returns immediately when
                    // filled($this->getState()) is true, so an already
                    // populated field never re-reads the relationship no
                    // matter how many times loadStateFromRelationships() is
                    // called. Clearing state first — safe here, this branch
                    // only runs on a field already judged clean — lets that
                    // guard pass and the poll's refill actually take effect.
                    $component->state(null);
                }

                $component->loadStateFromRelationships(true);
            }

            if ($this->autosaveRelationStateHash($components) !== $before) {
                $refilled[$path] = $entry;
            }
        }

        if ($refilled === []) {
            return ['refreshed' => [], 'stale' => $stale];
        }

        $after = $this->autosaveDehydrateForPoll();

        foreach ($refilled as $path => ['kind' => $kind, 'components' => $components]) {
            if (array_key_exists($path, $after)) {
                $this->acknowledgeAutosaveRefreshedField($path, $after[$path]);
            }

            if ($kind === 'media') {
                if (method_exists($this, 'acknowledgeAutosaveRefreshedUpload')) {
                    $this->acknowledgeAutosaveRefreshedUpload($components[0]);
                }
            } else {
                $this->acknowledgeAutosaveRefreshedRelation($path, $components);
            }

            // The raw state is what the browser holds under this path; the
            // controller folds it into its baseline as-is.
            $refreshed[$path] = method_exists($components[0], 'getRawState') ? $components[0]->getRawState() : data_get($after, $path);
        }

        return ['refreshed' => $refreshed, 'stale' => $stale];
    }

    /**
     * Whether a polled field still holds what was last acknowledged: a media
     * field by its upload hash; a relation by its relationship hash where the
     * trait keeps one, else by the field hash of the payload it folds into.
     *
     * @param  'relation'|'media'  $kind
     * @param  array<int, object>  $components
     * @param  array<string, mixed>  $current
     */
    protected function autosaveRelationFieldIsClean(string $kind, string $path, array $components, array $current): bool
    {
        // Media hooks live on the uploads trait, looked up like autosaveUploadFields().
        if ($kind === 'media') {
            return method_exists($this, 'autosaveUploadFieldIsClean') && $this->autosaveUploadFieldIsClean($components[0]);
        }

        if (array_key_exists($path, $current) && ! $this->autosaveFieldIsClean($path, $current[$path])) {
            return false;
        }

        if (property_exists($this, 'autosaveRelationshipHashes') && method_exists($this, 'autosaveRelationshipHash')) {
            return ($this->autosaveRelationshipHashes[$path] ?? null) === $this->autosaveRelationshipHash($components);
        }

        return array_key_exists($path, $current);
    }

    /**
     * Record a refilled relation's state as its new acknowledged state; traits with a relationship hash override.
     *
     * @param  array<int, object>  $components
     */
    protected function acknowledgeAutosaveRefreshedRelation(string $path, array $components): void {}

    /** @param array<int, object> $components */
    protected function autosaveRelationStateHash(array $components): string
    {
        $states = [];

        foreach ($components as $index => $component) {
            $states[$index] = method_exists($component, 'getRawState') ? $component->getRawState() : null;
        }

        return $this->hashAutosaveValue($states);
    }

    protected function rememberAutosaveSyncedRelations(object $record): void
    {
        if (! $this->autosavePollsRelationships()) {
            $this->autosaveSyncedRelationHashes = [];

            return;
        }

        // A poll already read them this request; reading again would double
        // the detector's cost. A write invalidates them, so the persist phase
        // clears this first.
        $this->autosaveSyncedRelationHashes = $this->autosaveReadRelationFingerprints
            ?? $this->autosaveRelationFingerprints($record)['fingerprints'];
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
     * @param  bool  $polling  A poll with `poll_relationships` on also refills clean upload columns.
     * @return array<string, true>
     */
    protected function autosaveRefreshablePaths(object $record, array $current, bool $polling = false): array
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

        if (method_exists($this, 'autosaveUploadFields') && ! ($polling && $this->autosavePollsRelationships())) {
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
            // Through the base builder: Eloquent would cast the value to a
            // Carbon, which never equals the raw attribute string the hash
            // was built from, and the fast path would never hit.
            $stamp = $record->newQuery()->toBase()->where($record->getQualifiedKeyName(), $record->getKey())->value($column);
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
        $this->rememberAutosaveSyncedRelations($record);
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
