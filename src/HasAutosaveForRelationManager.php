<?php

namespace Lenorix\FilamentAutosave;

use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Locked;

/**
 * Autosave for a Filament relation manager's action modals, with nothing to
 * wire up beyond `use HasAutosaveForRelationManager;`. It is `HasAutosaveForForm`
 * with every default a relation manager can infer already filled in: the
 * mounted action's schema is the form, its scope is the owner, the
 * relationship, the action, and the row being edited (so create and edit
 * modals of different rows never share a draft or an Undo snapshot), and the
 * baseline state re-arms itself every time a different modal opens or the
 * current one closes.
 *
 * `mountHasAutosaveForForm()` still runs; you never call it yourself.
 */
trait HasAutosaveForRelationManager
{
    use HasAutosaveForForm;

    /**
     * The scope the baseline below was last built for, or `null` while no
     * modal is open. Livewire property, so it survives between requests.
     *
     * @internal
     */
    #[Locked]
    public ?string $autosaveRelationManagerScope = null;

    /**
     * Call the base trait's own mount hook; Livewire lifecycle, not an
     * extension point.
     *
     * @internal
     */
    public function mountHasAutosaveForRelationManager(): void
    {
        $this->mountHasAutosaveForForm();
        $this->autosaveRelationManagerScope = $this->currentAutosaveModalScope();
    }

    /**
     * Re-arm the per-modal baseline when the mounted action changes: a fresh
     * baseline for a newly opened modal, everything cleared when it closes.
     * Livewire lifecycle, not an extension point.
     *
     * @internal
     */
    public function dehydrateHasAutosaveForRelationManager(): void
    {
        if (! $this->isAutosaveEnabled()) {
            return;
        }

        $scope = $this->currentAutosaveModalScope();

        if ($scope === $this->autosaveRelationManagerScope) {
            return;
        }

        $this->autosaveRelationManagerScope = $scope;
        $this->resetAutosaveModalState();

        if ($scope !== null) {
            $this->mountHasAutosaveForForm();
        }
    }

    /**
     * The mounted action's schema is the form; no schema is open otherwise.
     *
     * @api
     */
    protected function resolveAutosaveForm(): ?object
    {
        if ($this->getMountedAction() === null) {
            return null;
        }

        try {
            return $this->getMountedActionSchema();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Owner, relationship, action, and row: distinct for every modal so
     * drafts and Undo snapshots never leak between rows or relation managers.
     *
     * @api
     */
    protected function getAutosaveFormContext(): string
    {
        $context = ['relation:'.static::getRelationshipName()];

        $owner = $this->getOwnerRecord();

        if ($owner instanceof Model && $owner->getKey() !== null) {
            $context[] = 'owner:'.get_class($owner).':'.$owner->getKey();
        }

        $action = $this->getMountedAction();

        if ($action !== null) {
            $context[] = 'action:'.$action->getName();
            $context[] = 'row:'.$this->currentAutosaveModalRowKey($action);
        }

        return implode('|', $context);
    }

    /** Null while no modal is open, otherwise the scope its baseline is for. */
    protected function currentAutosaveModalScope(): ?string
    {
        if ($this->getMountedAction() === null) {
            return null;
        }

        try {
            return $this->getAutosaveFormContext();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function currentAutosaveModalRowKey(object $action): string
    {
        if (! method_exists($action, 'getRecord')) {
            return 'new';
        }

        try {
            $record = $action->getRecord(withDefault: false);
        } catch (\Throwable) {
            return 'new';
        }

        return $record instanceof Model && $record->getKey() !== null
            ? (string) $record->getKey()
            : 'new';
    }

    /** Everything a modal accumulates while it is open, back to idle. */
    protected function resetAutosaveModalState(): void
    {
        $this->autosaveCanUndo = false;
        $this->autosaveHasDraft = false;
        $this->autosaveFieldHashes = [];
        $this->autosavePollMs = 0;
        $this->autosaveSnapshotHash = '';
        $this->autosaveObservedHash = '';
        $this->autosaveSyncedAttributeHashes = [];
        $this->autosavePendingFields = [];
        $this->autosaveValidationErrors = [];
        $this->autosaveValidationKeys = [];
        $this->resetAutosaveUploadHashes();
    }
}
