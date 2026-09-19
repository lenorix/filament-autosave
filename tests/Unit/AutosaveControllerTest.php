<?php

use Lenorix\FilamentAutosave\AutosaveStatus;

function controllerMarkup(string $mode = 'edit', int $debounce = 2300): string
{
    return view('filament-autosave::autosave-controller', [
        'mode' => $mode,
        'debounce' => $debounce,
        'statusMeta' => AutosaveStatus::statusMeta(),
    ])->render();
}

test('the inline controller receives the resolved mode, delay, and status metadata', function () {
    $markup = controllerMarkup(mode: 'create', debounce: 2300);

    expect($markup)
        ->toContain('debounce: 2300')
        ->toContain('mode: \'create\'')
        ->toContain('autosave-status')
        ->toContain('draft_available')
        ->toContain('saveResults')
        ->toContain('fadeMs');
});

test('the controller watches form state and schedules one debounced save', function () {
    $markup = controllerMarkup();

    expect($markup)
        ->toContain('this.$watch')
        ->toContain('JSON.stringify(this.stateValue())')
        ->toContain('clearTimeout(this.timer)')
        ->toContain('this.timer = setTimeout')
        ->toContain('this.$wire.autosave()');
});

test('the controller resolves a configurable Livewire form state path', function () {
    expect(controllerMarkup())
        ->toContain('this.$wire.autosaveDataPath')
        ->toContain('stateValue()')
        ->toContain('this.statePath.split');
});

test('the controller watches the server-side snapshot hash', function () {
    $markup = controllerMarkup();

    expect($markup)
        ->toContain('this.$wire.autosaveObservedHash')
        ->toContain('current !== this.baselineJson && current !== this.lastSyncedStateJson')
        ->toContain('this.onDataChanged()');
});

test('the controller polls for other editors\' changes only while idle and visible, and backs off on errors', function () {
    $markup = controllerMarkup();

    expect($markup)
        ->toContain('this.pollMs = Number(this.$wire.autosavePollMs) || 0')
        ->toContain('await this.$wire.syncAutosave()')
        // Never race a pending or in-flight save.
        ->toContain('|| this.savePending')
        ->toContain('|| this.status === statuses.unsaved')
        ->toContain('|| this.status === statuses.saving')
        // Pause in background tabs, sync at once when they come back.
        ->toContain("document.visibilityState !== 'visible'")
        ->toContain("document.addEventListener('visibilitychange', this._visibilityHandler)")
        ->toContain('this.schedulePoll(0)')
        // Exponential backoff after three consecutive failures, capped at a minute.
        ->toContain('if (this.pollErrors < 3)')
        ->toContain('Math.min(this.pollMs * Math.pow(2, this.pollErrors - 2), 60000)')
        // A refill is the server's mutation, never a user edit.
        ->toContain('if (newVal === this.lastSyncedStateJson)')
        ->toContain('this.absorbRefreshedIntoBaseline()')
        ->toContain("document.removeEventListener('visibilitychange', this._visibilityHandler)");
});

test('the controller waits for uploads belonging to this component', function () {
    $markup = controllerMarkup();

    expect($markup)
        ->toContain('uploadsPending: 0')
        ->toContain("'livewire-upload-start'")
        ->toContain("'livewire-upload-finish'")
        ->toContain("'livewire-upload-error'")
        ->toContain("'livewire-upload-cancel'")
        ->toContain("e.target?.closest?.('[wire\\\\:id]') === mine")
        ->toContain('this.uploadsPending || this.savePending');
});

test('the controller resumes change detection after submit', function () {
    $markup = controllerMarkup();

    expect($markup)
        ->toContain("document.addEventListener('submit'")
        ->toContain('this.cancelled = true')
        ->toContain('this.cancelled = false');
});

test('the controller prevents overlapping saves and preserves changes made during a save', function () {
    $markup = controllerMarkup();

    expect($markup)
        ->toContain('this.savePending')
        ->toContain('this.sentJson')
        ->toContain('changedDuringSave')
        ->toContain('this.onDataChanged()');
});

test('restore, undo, and discard call their Livewire actions and handle failures', function () {
    $markup = controllerMarkup();

    expect($markup)
        ->toContain('this.runWireAction(\'undoAutosave\')')
        ->toContain('this.runWireAction(\'restoreDraft\')')
        ->toContain('this.$wire.discardDraft()')
        ->toContain('this.setStatus(statuses.error)');
});

test('status events update the indicator and reset settled states after a delay', function () {
    $markup = controllerMarkup();

    expect($markup)
        ->toContain('this.$wire.$on(statuses.event')
        ->toContain('this.setStatus(data.status, data.timestamp || null, data.errors || {}, data.refreshed || {}, data.pending || [], data.stale || [])')
        ->toContain('this.serverBaselineJson')
        ->toContain('this.setStatePath(baseline, path, value)')
        ->toContain('this.isSettled(newStatus)')
        ->toContain('this.fadeTimer = setTimeout');
});

test('destroying the controller cancels timers and unregisters browser listeners', function () {
    $markup = controllerMarkup();

    expect($markup)
        ->toContain('this.destroyed = true')
        ->toContain('document.removeEventListener')
        ->toContain("'livewire-upload-start'")
        ->toContain("'livewire-upload-finish'")
        ->toContain("'livewire-upload-error'")
        ->toContain("'livewire-upload-cancel'")
        ->toContain('this._offStatus?.()');
});

test('every status key the views read from the Alpine scope exists in the status metadata', function () {
    $views = [
        file_get_contents(__DIR__.'/../../resources/views/autosave-indicator.blade.php'),
        file_get_contents(__DIR__.'/../../resources/views/autosave-controller.blade.php'),
    ];

    preg_match_all('/statuses\.([a-zA-Z]+)/', implode("\n", $views), $matches);
    $used = array_values(array_unique($matches[1]));

    expect($used)->not->toBeEmpty()
        ->and(array_values(array_diff($used, array_keys(AutosaveStatus::statusMeta()))))->toBe([]);
});

test('the controller exposes the status metadata on the Alpine data object', function () {
    // The indicator's x-show / x-if expressions evaluate in Alpine's data
    // scope, not inside the IIFE closure, so `statuses` must be a property.
    expect(controllerMarkup())->toContain('statuses: statuses,');
});
