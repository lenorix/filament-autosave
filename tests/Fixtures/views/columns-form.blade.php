<div>
    @include('filament-autosave::autosave-indicator', [
        'mode' => 'form',
        'debounce' => $autosaveDebounceMs ?? 1500,
    ])

    {{ $this->form }}

    <button type="button" wire:click="generateSlug" data-fixture-action="generate-slug">Generate slug</button>
</div>
