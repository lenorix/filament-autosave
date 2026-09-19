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

    /** Whether a previously autosaved draft waits to be restored for this page. */
    protected function autosaveDraftAvailable(): bool
    {
        return $this->autosaveStore()->restoreDraft($this->getAutosaveCacheKey()) !== null;
    }

    /**
     * Fill the form from the stored draft.
     *
     * @api
     */
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

            $this->dispatchAutosaveStatus(AutosaveStatus::Restored);
        } catch (\Throwable $e) {
            $this->handleAutosaveFailure($e, 'restore');
        }
    }

    /**
     * Delete the stored draft.
     *
     * @api
     */
    public function discardDraft(): void
    {
        $this->authorizeAutosaveAccess();

        $this->clearAutosaveDraft();
        $this->dispatchAutosaveIdle();
    }

    /**
     * Delete the stored draft after an explicit persist.
     *
     * @api
     */
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
