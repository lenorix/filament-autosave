<?php

namespace Lenorix\FilamentAutosave;

use Livewire\Attributes\Locked;

/**
 * Draft lifecycle shared by create pages and recordless generic forms.
 *
 * A draft is an uncommitted copy of an autosave that never reached a record.
 * The implementing trait only has to provide getAutosaveCacheKey().
 */
trait HasAutosaveDraft
{
    #[Locked]
    public bool $autosaveHasDraft = false;

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
            $this->autosaveDraftRestored();
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

    /** Re-sync request-local hash state after the draft has been filled in. */
    protected function autosaveDraftRestored(): void {}

    protected function getAutosaveCacheTtl(): int
    {
        return AutosavePlugin::resolve()->getCacheTtl();
    }
}
