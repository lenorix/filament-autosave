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
        ->toContain('this.$wire.syncAutosave(this.mergeSync.baseHashes())')
        ->toContain('this.$wire.syncAutosave()')
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
        // Uploads block the request outright; an in-flight save queues it instead.
        ->toContain('if (this.uploadsPending || this.destroyed)')
        ->toContain('if (this.savePending || this.status === statuses.saving)');
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

test('the controller queues a save requested mid-flight and replays it at most once', function () {
    $markup = controllerMarkup();

    expect($markup)
        ->toContain('this.saveQueued = true')
        ->toContain('const queued = this.saveQueued')
        ->toContain('JSON.stringify(this.stateValue()) === this.baselineJson');
});

test('a save asked for while a poll is in flight waits for the poll and is replayed by it', function () {
    $markup = controllerMarkup();

    // The browser suite (AutosaveControllerResilienceTest) drives the real
    // timing; this pins the two halves of the hand-off in the markup.
    expect($markup)
        ->toContain("if (this.pollInFlight) {\n                this.saveQueued = true")
        ->toContain('if (this.saveQueued && !this.savePending) {');
});

test('an unchanged reply never demotes a settled badge still inside its fade window', function () {
    $markup = controllerMarkup();

    expect($markup)
        ->toContain('holdsFreshResult()')
        ->toContain('restoreHeldResult()')
        ->toContain('this.heldResult = { status: newStatus, until: Date.now() + fadeMs }')
        ->toContain('newStatus === statuses.idle && this.holdsFreshResult()');
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
        ->toContain('this.setStatus(data.status, data.timestamp || null, data.errors || {}, refreshed, data.pending || [], data.stale || [])')
        ->toContain('this.receiveMerge(data)')
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
        ->toContain("window.removeEventListener('beforeunload', this._unloadHandler)")
        ->toContain('this._offStatus?.()');
});

test('a save request that resolves without a status falls back to unsaved or idle', function () {
    $markup = controllerMarkup();

    expect($markup)
        ->toContain("if (this.status === statuses.saving) {\n                    console.warn('[filament-autosave]")
        ->toContain('this.status = JSON.stringify(this.stateValue()) !== this.baselineJson ? statuses.unsaved : statuses.idle');
});

test('an edit still inside the debounce is flushed when the tab is hidden or the page is left', function () {
    $markup = controllerMarkup();

    // beforeunload on purpose: Livewire sends a call a few milliseconds
    // after it is queued, and by pagehide no timer runs any more.
    expect($markup)
        ->toContain("window.addEventListener('beforeunload', this._unloadHandler)")
        ->not->toContain("'pagehide'")
        ->toContain('this.flush()')
        ->toContain('this.flush(true)')
        ->toContain('options.keepalive = true')
        ->toContain("window.Livewire.hook('request'");
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

test('the controller watches server-side state changes in edit and form modes alike', function () {
    // The controller is inlined into an HTML attribute, so quotes come back
    // entity-encoded; decode before looking for the mode gate.
    $js = html_entity_decode(view('filament-autosave::autosave-controller', [
        'debounce' => 500, 'mode' => 'form', 'statusMeta' => AutosaveStatus::statusMeta(),
    ])->render(), ENT_QUOTES);

    $watch = strpos($js, 'this.$watch(() => this.$wire.autosaveObservedHash');

    expect($watch)->not->toBeFalse()
        ->and(substr($js, max(0, $watch - 80), 80))->not->toContain("if (mode === 'edit')");
});

test('the controller merges rich editor documents inside the live editor', function () {
    $markup = controllerMarkup();

    expect($markup)
        // A rich field is told apart from the DOM and its base kept as the editor's JSON.
        ->toContain('rich: {')
        ->toContain('is: (path) => this.isRichField(path)')
        ->toContain('serialize: (path, value) => this.richSerialize(path, value)')
        ->toContain('window.FilamentAutosaveRichMerge.element(')
        ->toContain('window.FilamentAutosaveRichMerge.docs.normalize(found.editor, value)')
        // The reply is read against a copy of what was sent, not the live state.
        ->toContain('JSON.parse(JSON.stringify(values[path]))')
        // Applied as one transaction, with Filament's own reset skipped once.
        ->toContain('data.shouldUpdateState = false')
        ->toContain('rich.apply.toEditor(editor, target, { before: sync })')
        ->toContain("rich.merge.blocks(sentDoc, live, target, 'theirs')")
        ->toContain("rich.merge.blocks(baseDoc, live, target, 'ours')")
        ->toContain('rich.apply.mapSelection(')
        // Refills of a clean rich field go the same way instead of a reset.
        ->toContain('updates[path] = { rich: true, value, base: null, sent: null, contended: false }')
        // Conflicts are previewed as text and recovered inside the editor.
        ->toContain('conflict.preview = window.FilamentAutosaveRichMerge.docs.preview(')
        ->toContain('window.FilamentAutosaveRichMerge.apply.recover(found.editor, conflict)');
});
