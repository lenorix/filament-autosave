<?php

namespace Lenorix\FilamentAutosave;

trait HasAutosaveForCreate
{
    use HasAutosaveBase;
    use HasAutosaveDraft;

    protected bool $autosaveRecordWasCreated = false;

    /**
     * Livewire lifecycle; not an extension point.
     *
     * @internal
     */
    public function mountHasAutosaveForCreate(): void
    {
        if (! $this->initializeAutosaveState()) {
            return;
        }

        $this->autosaveDebounceMs = $this->getAutosaveDebounce();

        $this->autosaveHasDraft = $this->autosaveDraftAvailable();

        $this->autosaveSnapshotHash = $this->currentAutosaveSnapshotHash();
    }

    /**
     * Background entry point: never throws, reports through the indicator.
     *
     * @api
     */
    public function autosave(): void
    {
        $this->performAutosave(function (array $data): bool {
            $existing = $this->autosaveStore()->restoreDraft($this->getAutosaveCacheKey());
            $hasMeaningfulValue = array_filter(
                $data,
                static fn ($value) => $value !== null && $value !== '' && $value !== [],
            ) !== [];

            // An entirely blank new form does not need a draft. Once a draft
            // exists, however, blank values are deliberate deletions and must
            // be retained so restore does not resurrect old input.
            if (! $hasMeaningfulValue && $existing === null) {
                $this->clearAutosaveDraft();

                return false;
            }

            $payload = $data;

            $this->autosaveStore()->storeDraft(
                $this->getAutosaveCacheKey(),
                $payload,
                $this->getAutosaveCacheTtl(),
            );

            return true;
        });
    }

    public function create(bool $another = false): void
    {
        if (! $this->shouldWrapCreate()) {
            return;
        }

        $this->autosaveRecordWasCreated = false;

        parent::create($another);

        // A new create clears the record, so rememberData() records success first.
        if ($this->autosaveRecordWasCreated || $this->getRecord()?->exists) {
            $this->clearAutosaveDraft();
        }
    }

    /**
     * Hook so pages without a persistent create() can opt out of the wrapper.
     */
    protected function shouldWrapCreate(): bool
    {
        $parent = get_parent_class(static::class);

        return $parent !== false && method_exists($parent, 'create');
    }

    // Filament calls this after saving and before it clears the record.
    protected function rememberData(): void
    {
        $this->autosaveRecordWasCreated = true;

        if (method_exists(parent::class, 'rememberData')) {
            parent::rememberData();
        }
    }

    protected function getAutosaveCacheKey(): string
    {
        return $this->autosaveStore()->cacheKey(static::class);
    }
}
