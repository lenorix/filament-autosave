@php
    use Illuminate\Support\HtmlString;

    $debounce = $debounce ?? 1500;
    $showTimestamp = $showTimestamp ?? true;
    $mode = $mode ?? 'edit';
    $statusMeta = \Lenorix\FilamentAutosave\AutosaveStatus::statusMeta();

    // Every visual element is a Filament component (badge, link, callout), so
    // light/dark theming and typography come from the panel theme; the view
    // ships no CSS of its own. Alpine-bound text lives in spans passed through
    // the callout's HtmlString props.
    $alpineText = fn (string $expression, string $extra = ''): HtmlString => new HtmlString(
        sprintf('<span x-text="%s"%s></span>', e($expression), $extra),
    );

    // Alpine expression for the "Stored at HH:MM" label, shared by the badge and the callout.
    $savedAtExpression = \Illuminate\Support\Js::from(__('filament-autosave::autosave.saved_at').' ').' + timestamp';
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
    x-bind:data-autosave-status="status"
>
    <template x-if="status === statuses.draftAvailable">
        <x-filament::badge color="info" icon="heroicon-m-document-text">
            {{ __('filament-autosave::autosave.draft_available') }}
            <x-filament::link tag="button" type="button" size="sm" color="success" x-on:click="restore()" data-autosave-action="restore">
                {{ __('filament-autosave::autosave.restore') }}
            </x-filament::link>
            <x-filament::link tag="button" type="button" size="sm" color="danger" x-on:click="discard()" data-autosave-action="discard">
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

    {{-- Saved with nothing left over: a single badge. --}}
    <template x-if="status === statuses.saved && ! pendingFields.length">
        <x-filament::badge color="success" icon="heroicon-m-check-circle">
            @if ($showTimestamp)
                <span x-text="{{ $savedAtExpression }}"></span>
            @else
                {{ __('filament-autosave::autosave.saved') }}
            @endif
            {{-- Not gated on $mode: record-backed generic forms offer Undo too; drafts keep autosaveCanUndo false. --}}
            <x-filament::link tag="button" type="button" size="sm" x-on:click="undo()" x-show="$wire.autosaveCanUndo" data-autosave-action="undo">
                {{ __('filament-autosave::autosave.undo') }}
            </x-filament::link>
        </x-filament::badge>
    </template>

    {{-- Saved, but some fields were skipped: the callout lists them as badges. --}}
    <template x-if="status === statuses.saved && pendingFields.length">
        <x-filament::callout
            color="success"
            icon="heroicon-m-check-circle"
            :heading="$showTimestamp ? $alpineText($savedAtExpression) : __('filament-autosave::autosave.saved')"
            :description="__('filament-autosave::autosave.pending')"
        >
            <x-slot name="footer">
                <template x-for="field in pendingFields" :key="field">
                    <x-filament::badge color="gray" size="sm">
                        <span x-text="field"></span>
                    </x-filament::badge>
                </template>
            </x-slot>
            <x-slot name="controls">
                <x-filament::link tag="button" type="button" size="sm" x-on:click="undo()" x-show="$wire.autosaveCanUndo" data-autosave-action="undo">
                    {{ __('filament-autosave::autosave.undo') }}
                </x-filament::link>
            </x-slot>
        </x-filament::callout>
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

    {{-- Validation: messages in the description, skipped fields as badges. --}}
    <template x-if="status === statuses.validation">
        <x-filament::callout
            color="warning"
            icon="heroicon-m-exclamation-triangle"
            :heading="__('filament-autosave::autosave.validation')"
            :description="$alpineText(
                'Object.values(validationErrors).map((messages) => messages.join(\' \')).join(\' · \')',
                ' data-autosave-validation-messages',
            )"
        >
            <x-slot name="footer">
                <template x-if="pendingFields.length">
                    <x-filament::badge color="gray" size="sm" data-autosave-pending-label>
                        {{ __('filament-autosave::autosave.pending') }}
                    </x-filament::badge>
                </template>
                <template x-for="field in pendingFields" :key="field">
                    <x-filament::badge color="warning" size="sm">
                        <span x-text="field"></span>
                    </x-filament::badge>
                </template>
            </x-slot>
        </x-filament::callout>
    </template>

    {{-- Synced from another editor with nothing in conflict: a single badge. --}}
    <template x-if="status === statuses.synced && ! staleFields.length">
        <x-filament::badge color="info" icon="heroicon-m-arrow-path" data-autosave-synced>
            {{ __('filament-autosave::autosave.synced') }}
        </x-filament::badge>
    </template>

    {{-- Synced, but fields the user is editing also changed elsewhere. --}}
    <template x-if="status === statuses.synced && staleFields.length">
        <x-filament::callout
            color="info"
            icon="heroicon-m-arrow-path"
            :heading="__('filament-autosave::autosave.synced')"
            :description="__('filament-autosave::autosave.stale')"
            data-autosave-synced
        >
            <x-slot name="footer">
                <div data-autosave-stale>
                    <template x-for="field in staleFields" :key="field">
                        <x-filament::badge color="warning" size="sm">
                            <span x-text="field"></span>
                        </x-filament::badge>
                    </template>
                </div>
            </x-slot>
        </x-filament::callout>
    </template>
</div>
