@php
    $debounce = $debounce ?? 1500;
    $showTimestamp = $showTimestamp ?? true;
    $mode = $mode ?? 'edit';
    $statusMeta = \Lenorix\FilamentAutosave\AutosaveStatus::statusMeta();
@endphp

<div
    x-data="{{ view('filament-autosave::autosave-controller', [
        'debounce' => $debounce,
        'mode' => $mode,
        'statusMeta' => $statusMeta,
    ])->render() }}"
    x-show="status !== statuses.idle"
    x-transition.opacity.duration.150ms
    class="fi-autosave-indicator"
    role="status"
    aria-live="polite"
    x-cloak
>
    <template x-if="status === statuses.draftAvailable">
        <x-filament::badge color="info" icon="heroicon-m-document-text">
            {{ __('filament-autosave::autosave.draft_available') }}
            <x-filament::link tag="button" type="button" size="sm" color="success" x-on:click="restore()">
                {{ __('filament-autosave::autosave.restore') }}
            </x-filament::link>
            <x-filament::link tag="button" type="button" size="sm" color="danger" x-on:click="discard()">
                {{ __('filament-autosave::autosave.discard') }}
            </x-filament::link>
        </x-filament::badge>
    </template>

    <template x-if="status === statuses.unsaved">
        <x-filament::badge color="warning" icon="heroicon-m-pencil-square">
            {{ __('filament-autosave::autosave.unsaved') }}
        </x-filament::badge>
    </template>

    <template x-if="status === statuses.saving">
        <x-filament::badge
            color="gray"
            :icon="\Filament\Support\generate_loading_indicator_html(size: \Filament\Support\Enums\IconSize::Small)"
        >
            {{ __('filament-autosave::autosave.saving') }}
        </x-filament::badge>
    </template>

    <template x-if="status === statuses.saved">
        <div class="fi-autosave-stack">
            <x-filament::badge color="success" icon="heroicon-m-check-circle">
                @if ($showTimestamp)
                    <span x-text="@js(__('filament-autosave::autosave.saved_at') . ' ') + timestamp"></span>
                @else
                    {{ __('filament-autosave::autosave.saved') }}
                @endif
                @if ($mode === 'edit')
                    <x-filament::link tag="button" type="button" size="sm" x-on:click="undo()" x-show="$wire.autosaveCanUndo">
                        {{ __('filament-autosave::autosave.undo') }}
                    </x-filament::link>
                @endif
            </x-filament::badge>
            <template x-if="pendingFields.length">
                <p class="fi-autosave-note">
                    {{ __('filament-autosave::autosave.pending') }}
                    <span x-text="pendingFields.join(', ')"></span>
                </p>
            </template>
        </div>
    </template>

    <template x-if="status === statuses.undone">
        <x-filament::badge color="info" icon="heroicon-m-arrow-uturn-left">
            {{ __('filament-autosave::autosave.undone') }}
        </x-filament::badge>
    </template>

    <template x-if="status === statuses.restored">
        <x-filament::badge color="success" icon="heroicon-m-arrow-path">
            {{ __('filament-autosave::autosave.restored') }}
        </x-filament::badge>
    </template>

    <template x-if="status === statuses.error">
        <x-filament::badge color="danger" icon="heroicon-m-x-circle">
            {{ __('filament-autosave::autosave.error') }}
        </x-filament::badge>
    </template>

    <template x-if="status === statuses.conflict">
        <x-filament::badge color="warning" icon="heroicon-m-exclamation-triangle">
            {{ __('filament-autosave::autosave.conflict') }}
        </x-filament::badge>
    </template>

    <template x-if="status === statuses.validation">
        <div class="fi-autosave-stack">
            <x-filament::badge color="warning" icon="heroicon-m-exclamation-triangle">
                {{ __('filament-autosave::autosave.validation') }}
            </x-filament::badge>
            <ul class="fi-autosave-list">
                <template x-for="(messages, field) in validationErrors" :key="field">
                    <li x-text="messages.join(' ')"></li>
                </template>
            </ul>
            <template x-if="pendingFields.length">
                <p class="fi-autosave-note">
                    {{ __('filament-autosave::autosave.pending') }}
                    <span x-text="pendingFields.join(', ')"></span>
                </p>
            </template>
        </div>
    </template>
</div>
