<div>
    @include('filament-autosave::autosave-indicator', [
        'mode' => 'form',
        'debounce' => $autosaveDebounceMs ?? 1500,
    ])

    {{ $this->form }}
</div>
