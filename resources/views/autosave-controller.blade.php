(function ({ debounce = 1500, mode = 'edit', statuses = {}, mergeFields = [] }) {
    // Shared by every controller on the page: while a flush on unload is
    // out, Livewire's request is sent with keepalive.
    const keepalive = window.FilamentAutosaveKeepalive = window.FilamentAutosaveKeepalive || { pending: 0, hooked: false }
    const installKeepaliveHook = () => {
        if (keepalive.hooked || typeof window.Livewire?.hook !== 'function') {
            return
        }

        keepalive.hooked = true
        window.Livewire.hook('request', ({ options }) => {
            if (keepalive.pending > 0 && options) {
                options.keepalive = true
            }
        })
    }

    return {
        // The indicator's x-show / x-if expressions evaluate in this data
        // scope, not inside the closure, so the metadata must be a property.
        statuses: statuses,
        status: statuses.idle,
        timestamp: null,
        validationErrors: {},
        pendingFields: [],
        timer: null,
        fadeTimer: null,
        baselineJson: null,
        sentJson: null,
        savePending: false,
        // A save() asked for while another is in flight. It is replayed once
        // when that request finishes, and only if the state still differs.
        saveQueued: false,
        // Last settled result still inside its fade window, e.g. { status,
        // until }. An "unchanged" reply to a redundant save must not replace
        // a badge the user is still reading.
        heldResult: null,
        uploadsPending: 0,
        refreshedState: {},
        staleFields: [],
        serverBaselineJson: null,
        destroyed: false,
        cancelled: false,
        mode: mode,
        debounceMs: debounce,
        statePath: 'data',
        // Polling for other editors' changes. Runs only when this component
        // is idle (no debounce pending, no save in flight) and the tab is
        // visible, and backs off after repeated failures.
        pollMs: 0,
        pollTimer: null,
        pollInFlight: false,
        pollErrors: 0,
        // Live state as the last poll left it (dirty fields included), so the
        // watchers can tell the poll's own refill from a user edit.
        lastSyncedStateJson: null,
        // Word-level merging of plain-text fields (window.FilamentAutosaveMerge,
        // loaded by the indicator when the component lists merge fields).
        // mergeSync keeps the base every patch is built from; conflicts are
        // the other editor's words a merge discarded, kept until the user
        // recovers or dismisses them.
        mergeSync: null,
        conflicts: [],
        mergeSnapshots: {},
        mergeSent: null,

        init() {
            if (this.$wire.autosaveEnabled === false) {
                return
            }

            // Precedence across page, plugin and config is resolved on the PHP side.
            this.debounceMs = Number(this.$wire.autosaveDebounceMs) || debounce
            this.statePath = this.$wire.autosaveDataPath || 'data'
            this.cancelled = false

            this.rememberBaseline()

            if ((mode === 'edit' || mode === 'form') && mergeFields.length && window.FilamentAutosaveMerge) {
                // A rich editor's field holds a document; its base is kept
                // as the editor's own JSON, resolved lazily because the
                // editor is loaded after this controller.
                this.mergeSync = window.FilamentAutosaveMerge.createSync(mergeFields, {
                    rich: {
                        is: (path) => this.isRichField(path),
                        serialize: (path, value) => this.richSerialize(path, value),
                    },
                })
                this.mergeSync.seed(this.stateValue())
            }

            if (mode !== 'edit' && this.$wire.autosaveHasDraft) {
                this.status = statuses.draftAvailable
            }

            this.$watch(
                () => JSON.stringify(this.stateValue()),
                (newVal) => {
                    if (newVal === this.baselineJson) {
                        return
                    }

                    // A poll just refilled clean fields: that mutation is the
                    // server's, not the user's, so it must not re-mark the
                    // form unsaved or kick off a save.
                    if (newVal === this.lastSyncedStateJson) {
                        return
                    }

                    this.cancelled = false
                    this.onDataChanged()
                },
            )

            this._offStatus = this.$wire.$on(statuses.event, (params) => {
                const data = Array.isArray(params) ? params[0] : params
                // Merged values the server stored are acknowledged like a
                // refill: they belong in the baseline, not in the diff.
                const refreshed = this.richNormalized({ ...(data.refreshed || {}), ...this.mergeAcknowledged(data) })
                // Only a poll's own reply carries `stale` at all (a plain
                // autosave() response has no such key, since staleness is a
                // polling concept). Falling back to [] here would wipe a
                // stale list a poll just reported the moment an unrelated
                // local save -- e.g. the debounced attempt behind the very
                // edit that made a field stale -- resolves afterwards.
                const stale = 'stale' in data ? (data.stale || []) : this.staleFields
                this.setStatus(data.status, data.timestamp || null, data.errors || {}, refreshed, data.pending || [], stale)
                this.receiveMerge(data)
            })

            this.pollMs = Number(this.$wire.autosavePollMs) || 0

            // A tab going to the background, or the page being left, must
            // not sit on an edit the debounce has not flushed yet.
            this._visibilityHandler = () => {
                if (document.visibilityState === 'visible') {
                    this.schedulePoll(0)
                } else {
                    clearTimeout(this.pollTimer)
                    this.flush()
                }
            }
            document.addEventListener('visibilitychange', this._visibilityHandler)
            // beforeunload, not pagehide: Livewire buffers a call behind a
            // short timer, and by pagehide no timer runs any more.
            this._unloadHandler = () => this.flush(true)
            window.addEventListener('beforeunload', this._unloadHandler)

            if (this.pollMs > 0) {
                this.schedulePoll()
            }

            // Edit pages and record-backed generic forms both publish a
            // request-end hash covering state the deep data watcher cannot
            // see (upload state after a server-side remove or reorder). Create
            // drafts do not expose it, and $wire returns a callable for any
            // unknown name, so this has to be a mode check, not a property one.
            if (mode === 'edit' || mode === 'form') {
                this.$watch(() => this.$wire.autosaveObservedHash, () => {
                    const current = JSON.stringify(this.stateValue())

                    // The server hash also moves when a poll refills fields;
                    // only a state the user produced should reopen a save.
                    if (!this.savePending && current !== this.baselineJson && current !== this.lastSyncedStateJson) {
                        this.onDataChanged()
                    }
                })
            }

            const belongsToMe = (e) => {
                const mine = this.$el?.closest?.('[wire\\:id]')
                return mine && e.target?.closest?.('[wire\\:id]') === mine
            }
            this._uploadStart = (e) => {
                if (!belongsToMe(e)) return
                this.uploadsPending++
                clearTimeout(this.timer)
            }
            this._uploadEnd = (e) => {
                if (!belongsToMe(e)) return
                this.uploadsPending = Math.max(0, this.uploadsPending - 1)
                if (!this.uploadsPending) this.onDataChanged()
            }
            document.addEventListener('livewire-upload-start', this._uploadStart)
            for (const event of ['livewire-upload-finish', 'livewire-upload-error', 'livewire-upload-cancel']) {
                document.addEventListener(event, this._uploadEnd)
            }

            this._submitHandler = (e) => {
                const mine = this.$el?.closest?.('[wire\\:id]')
                const submitted = e.target?.closest?.('[wire\\:id]')

                if (mine && submitted && mine === submitted) {
                    this.cancelPending()
                }
            }
            document.addEventListener('submit', this._submitHandler)
        },

        cancelPending() {
            clearTimeout(this.timer)
            clearTimeout(this.fadeTimer)
            this.status = statuses.idle
            this.cancelled = true
        },

        // Send an edit still waiting on its debounce right now. When the
        // page is going away the request is marked keepalive so the browser
        // lets it finish after unload. Best effort: Livewire only sends a
        // few milliseconds later, and keepalive bodies are capped at 64 KB,
        // so a page torn down instantly or a huge form may still lose it.
        flush(unloading = false) {
            if (this.status !== statuses.unsaved || this.savePending || this.destroyed) {
                return
            }

            clearTimeout(this.timer)

            if (unloading) {
                installKeepaliveHook()
                keepalive.pending++
                this.save().finally(() => keepalive.pending--)

                return
            }

            this.save()
        },

        onDataChanged() {
            if (this.cancelled) {
                return
            }

            clearTimeout(this.timer)
            clearTimeout(this.fadeTimer)

            this.status = statuses.unsaved

            this.timer = setTimeout(() => {
                this.save()
            }, this.debounceMs)
        },

        async save() {
            if (this.uploadsPending || this.destroyed) {
                return
            }

            // Coalesce: one request at a time, replayed once afterwards if
            // the state moved meanwhile (see the finally block).
            if (this.savePending || this.status === statuses.saving) {
                this.saveQueued = true
                return
            }

            // A poll reply landing mid-save would be half applied: its
            // synced status is dropped while the refill still reaches the
            // state, and the watcher then reads that refill as a user edit.
            // Let the poll finish first; it replays this save.
            if (this.pollInFlight) {
                this.saveQueued = true
                return
            }

            // Nothing to send: the state already matches the last settled
            // baseline (typically the server's own refill after an upload or
            // a save). Skip the round trip and keep the badge that is showing.
            if (JSON.stringify(this.stateValue()) === this.baselineJson) {
                this.restoreHeldResult()
                return
            }

            this.savePending = true
            this.sentJson = JSON.stringify(this.stateValue())
            this.serverBaselineJson = this.sentJson
            this.refreshedState = {}
            this.status = statuses.saving

            try {
                await (this.mergeSync ? this.$wire.autosave(this.mergePatches()) : this.$wire.autosave())
            } catch (e) {
                this.setStatus(statuses.error)
            } finally {
                const changedDuringSave = JSON.stringify(this.stateValue()) !== (this.serverBaselineJson || this.sentJson)
                const queued = this.saveQueued
                this.saveQueued = false
                this.savePending = false
                this.sentJson = null
                this.serverBaselineJson = null
                this.refreshedState = {}

                if (this.destroyed || this.cancelled || this.status === statuses.error) {
                    return
                }

                // The request came back without a status: the badge would
                // stay on "saving" and every later save would be coalesced
                // away. The form is still dirty, say so and carry on.
                if (this.status === statuses.saving) {
                    console.warn('[filament-autosave] the autosave request finished without a status; the form is still unsaved.')
                    this.status = JSON.stringify(this.stateValue()) !== this.baselineJson ? statuses.unsaved : statuses.idle
                }

                // A queued or concurrent edit is replayed exactly once, and
                // only when there is genuinely new state to send. A queued
                // save whose state was already covered is dropped: replaying
                // it would come back "unchanged" and demote the badge.
                if (changedDuringSave) {
                    this.onDataChanged()
                } else if (queued) {
                    this.restoreHeldResult()
                }
            }
        },

        // Whether a settled result (saved, synced, undone...) is still inside
        // its fade window, i.e. the user is presumably still reading it.
        holdsFreshResult() {
            return this.heldResult !== null && Date.now() < this.heldResult.until
        },

        // Put a still-fresh settled badge back, re-arming its remaining fade.
        restoreHeldResult() {
            if (!this.holdsFreshResult()) {
                if (this.status === statuses.unsaved || this.status === statuses.saving) {
                    this.status = statuses.idle
                }

                return
            }

            clearTimeout(this.fadeTimer)
            this.status = this.heldResult.status
            this.fadeTimer = setTimeout(() => {
                this.status = statuses.idle
                this.heldResult = null
            }, Math.max(0, this.heldResult.until - Date.now()))
        },

        // Delay until the next poll: the configured interval, doubled per
        // failure once three have happened in a row, never above a minute.
        pollDelay() {
            if (this.pollErrors < 3) {
                return this.pollMs
            }

            return Math.min(this.pollMs * Math.pow(2, this.pollErrors - 2), 60000)
        },

        schedulePoll(delay = null) {
            clearTimeout(this.pollTimer)

            if (this.destroyed || this.pollMs <= 0) {
                return
            }

            this.pollTimer = setTimeout(() => this.poll(), delay ?? this.pollDelay())
        },

        // A save in progress or a debounce still pending means the wire state
        // is ahead of the server; polling then would race the write. Skip the
        // tick and try again after the next interval.
        pollBlocked() {
            return this.pollInFlight
                || this.savePending
                || this.uploadsPending > 0
                || this.status === statuses.unsaved
                || this.status === statuses.saving
                || document.visibilityState !== 'visible'
        },

        async poll() {
            if (this.destroyed) {
                return
            }

            if (this.pollBlocked()) {
                this.schedulePoll()
                return
            }

            this.pollInFlight = true

            try {
                await (this.mergeSync ? this.$wire.syncAutosave(this.mergeSync.baseHashes()) : this.$wire.syncAutosave())
                this.pollErrors = 0
            } catch (e) {
                this.pollErrors++
            } finally {
                this.pollInFlight = false
                this.schedulePoll()

                // A save asked for while the poll ran goes out now, on top
                // of the state the poll left (its refill is in the baseline).
                if (this.saveQueued && !this.savePending) {
                    this.saveQueued = false
                    this.save()
                }
            }
        },

        // --- Merging -------------------------------------------------------

        mergeInput(path) {
            return window.FilamentAutosaveMerge.apply.findInput(this.$el?.closest?.('[wire\\:id]'), this.statePath + '.' + path)
        },

        // --- Rich editors ---------------------------------------------------

        // Whether the field is a Filament rich editor: decided from the DOM
        // so it holds before the editor's own script has loaded.
        isRichField(path) {
            if (!window.FilamentAutosaveRichMerge) {
                return false
            }

            if (this.richFieldCache?.[path] === undefined) {
                this.richFieldCache = this.richFieldCache || {}
                this.richFieldCache[path] = window.FilamentAutosaveRichMerge.element(this.$el?.closest?.('[wire\\:id]'), this.statePath + '.' + path) !== null
            }

            return this.richFieldCache[path]
        },

        // Filament's editor bound to the field: `{ el, data, editor }` or null.
        richEditor(path) {
            return window.FilamentAutosaveRichMerge?.find(this.$el?.closest?.('[wire\\:id]'), this.statePath + '.' + path) || null
        },

        // The base a rich field is kept as: the editor's JSON for the value.
        // Before the editor exists the raw document is close enough; the
        // first save re-reads every base through the editor.
        richSerialize(path, value) {
            const found = this.richEditor(path)

            if (found) {
                return window.FilamentAutosaveRichMerge.docs.normalize(found.editor, value)
            }

            return typeof value === 'string' ? value : JSON.stringify(value ?? null)
        },

        // Rich values the server sent (a refill or a merge) as the editor
        // will report them once applied, so baselines compare equal.
        richNormalized(values) {
            const out = { ...values }

            for (const path of Object.keys(out)) {
                const found = this.mergeSync && this.mergeSync.isRich(path) ? this.richEditor(path) : null

                if (found) {
                    out[path] = window.FilamentAutosaveRichMerge.docs.toDoc(found.editor, out[path]).toJSON()
                }
            }

            return out
        },

        // Put a document into the editor changing only what differs, with
        // the Livewire state and both watchers told first. Filament's own
        // state watcher would reset the whole editor (and the caret) when the
        // server refilled the field: skip that one run.
        richApply(path, found, target, selection = null) {
            const rich = window.FilamentAutosaveRichMerge
            const { editor, data } = found

            // Filament's watcher runs once for the state write below (and
            // the server's refill it may be batched with); the flag is put
            // back if no run consumed it.
            data.shouldUpdateState = false
            setTimeout(() => {
                if (data.shouldUpdateState === false) {
                    data.shouldUpdateState = true
                }
            }, 0)

            const sync = (docJson) => {
                const expected = JSON.parse(JSON.stringify(this.stateValue()))
                this.setStatePath(expected, path, docJson)
                this.lastSyncedStateJson = JSON.stringify(expected)
                this.stateValue()[path] = docJson
            }

            const changed = rich.apply.toEditor(editor, target, { before: sync })

            if (!changed) {
                // The editor already shows it: align the state with the
                // editor's own JSON so the refill does not read as an edit.
                const docJson = editor.getJSON()

                if (JSON.stringify(this.stateValue()?.[path] ?? null) !== JSON.stringify(docJson)) {
                    sync(docJson)
                }
            }

            if (selection) {
                rich.apply.select(editor, selection)
            }
        },

        // Patches for the dirty mergeable fields, remembering what each
        // input held (value and caret) so the reply can be applied to it.
        mergePatches() {
            const values = this.stateValue()
            this.mergeSent = {}
            this.mergeSnapshots = {}

            for (const path of mergeFields) {
                // A copy: Livewire updates the state object in place when
                // the reply lands, and the reply must be read against what
                // was actually sent.
                this.mergeSent[path] = values?.[path] === undefined ? '' : JSON.parse(JSON.stringify(values[path]))

                if (this.mergeSync.isRich(path)) {
                    const found = this.richEditor(path)

                    // A base seeded before the editor existed is re-read
                    // through it now, so it compares with what we send.
                    if (found && !this.richRebased?.[path]) {
                        this.richRebased = { ...(this.richRebased || {}), [path]: true }
                        this.mergeSync.acknowledge(path, this.mergeSync.base(path))
                    }

                    this.mergeSnapshots[path] = found ? { selection: found.editor.state.selection.toJSON() } : null
                    continue
                }

                this.mergeSnapshots[path] = window.FilamentAutosaveMerge.apply.snapshot(this.mergeInput(path))
            }

            return this.mergeSync.patches(values)
        },

        // Merged values the server wrote (not the contended ones).
        mergeAcknowledged(data) {
            const acknowledged = {}

            if (this.mergeSync && data.merged) {
                for (const [path, value] of Object.entries(data.merged)) {
                    if (!data.patches?.[path]) {
                        acknowledged[path] = value
                    }
                }
            }

            return acknowledged
        },

        // Fold a save or poll reply into the bases, put the merged text
        // into the inputs around the caret, and list what was discarded.
        receiveMerge(data) {
            if (!this.mergeSync || !data) {
                return
            }

            const reply = data.status !== statuses.synced && this.mergeSent !== null
            const sent = reply ? this.mergeSent : null
            const snapshots = this.mergeSnapshots

            if (reply) {
                this.mergeSent = null
                this.mergeSnapshots = {}
            }

            // Undo and a draft restore rewrite the fields outside any merge:
            // whatever they hold now is the value the server has.
            if (typeof data.v !== 'number') {
                if (this.isSettled(data.status) && data.status !== statuses.idle) {
                    this.mergeSync.resync(this.stateValue())
                }

                return
            }

            // A reply that wrote nothing (error) leaves every base as it was.
            if (reply && !this.isSaveResult(data.status) && data.status !== statuses.validation) {
                return
            }

            // What the inputs hold right now: the state already carries the
            // server's values, but a textarea still shows what the user typed
            // while the request ran, and that is what the merge must keep.
            const live = JSON.parse(JSON.stringify(this.stateValue()))
            const editors = {}

            for (const path of mergeFields) {
                if (this.mergeSync.isRich(path)) {
                    editors[path] = this.richEditor(path)
                    live[path] = editors[path] ? editors[path].editor.getJSON() : live[path]
                    continue
                }

                const input = this.mergeInput(path)

                if (input) {
                    live[path] = input.value
                }
            }

            const { updates, conflicts } = this.mergeSync.receive(data, live, sent)

            // A clean rich field the server refilled: the same document
            // goes into the editor block by block, not through a reset.
            for (const [path, value] of Object.entries(data.refreshed || {})) {
                if (editors[path] && !updates[path]) {
                    updates[path] = { rich: true, value, base: null, sent: null, contended: false }
                }
            }

            for (const path of Object.keys(updates)) {
                if (!updates[path]?.rich) {
                    continue
                }

                const found = editors[path]
                const update = updates[path]
                delete updates[path]

                // Without an editor on the page there is nothing to merge
                // into; a refill already reached the state on its own.
                if (found) {
                    this.richReceive(path, found, update, sent ? snapshots[path] : null)
                }
            }

            for (const conflict of conflicts) {
                if (editors[conflict.path]) {
                    conflict.preview = window.FilamentAutosaveRichMerge.docs.preview(editors[conflict.path].editor, conflict.theirs)
                }
            }

            const paths = Object.keys(updates)

            if (paths.length) {
                // The inputs are about to change on the server's behalf, not
                // the user's: neither watcher may read it as a new edit.
                const expected = JSON.parse(JSON.stringify(this.stateValue()))

                for (const [path, value] of Object.entries(data.refreshed || {})) {
                    this.setStatePath(expected, path, value)
                }

                for (const path of paths) {
                    this.setStatePath(expected, path, updates[path])
                }

                this.lastSyncedStateJson = JSON.stringify(expected)

                for (const path of paths) {
                    const input = this.mergeInput(path)

                    if (input) {
                        window.FilamentAutosaveMerge.apply.toInput(input, updates[path], sent ? snapshots[path] : null)
                    } else {
                        this.stateValue()[path] = updates[path]
                    }
                }
            }

            if (conflicts.length) {
                this.conflicts = [...this.conflicts.filter((c) => !conflicts.some((n) => n.path === c.path)), ...conflicts]
            }

            // A contended field was not written: the adopted text is a fresh
            // local edit from the new base, and the next cycle retries it.
            if (sent && data.patches && Object.keys(data.patches).length && !this.savePending) {
                this.onDataChanged()
            }
        },

        // A document from the server into its editor. On a save reply the
        // server merged what we sent; anything typed since is merged back
        // in block by block, the server's blocks winning where both moved.
        // On a poll their value meets our unsaved edits from the shared
        // base, ours winning: the next save merges those word by word.
        richReceive(path, found, update, snapshot = null) {
            const rich = window.FilamentAutosaveRichMerge
            const { editor } = found
            const live = editor.state.doc
            let target = rich.docs.toDoc(editor, update.value)

            if (update.sent !== null) {
                const sentDoc = rich.docs.toDoc(editor, update.sent)

                if (!live.eq(sentDoc)) {
                    target = rich.merge.blocks(sentDoc, live, target, 'theirs')
                }
            } else if (update.base !== null && update.base !== undefined) {
                const baseDoc = rich.docs.toDoc(editor, update.base)

                if (!live.eq(baseDoc)) {
                    target = rich.merge.blocks(baseDoc, live, target, 'ours')
                }
            }

            // Filament may already have reset the editor to the refilled
            // state (the caret at the end): the selection to keep is the
            // one captured before the request, mapped to the new document.
            const selection = snapshot?.selection && live.eq(rich.docs.toDoc(editor, update.value)) && editor.state.selection.empty
                ? rich.apply.mapSelection(rich.docs.toDoc(editor, update.sent), target, snapshot.selection)
                : null

            // A contended field was not written: the adopted document is a
            // fresh edit from the new base, and receiveMerge re-arms the save.
            this.richApply(path, found, target, selection)
        },

        // Put the other editor's discarded words back where ours replaced
        // them; a real edit, so it is saved like any other.
        recoverConflict(index) {
            const conflict = this.conflicts[index]

            if (!conflict) {
                return
            }

            if (this.mergeSync.isRich(conflict.path)) {
                const found = this.richEditor(conflict.path)

                if (found) {
                    window.FilamentAutosaveRichMerge.apply.recover(found.editor, conflict)
                    this.conflicts = this.conflicts.filter((_, i) => i !== index)
                }

                return
            }

            const input = this.mergeInput(conflict.path)

            if (!input) {
                return
            }

            window.FilamentAutosaveMerge.apply.toInput(input, window.FilamentAutosaveMerge.apply.recover(input.value, conflict))
            this.conflicts = this.conflicts.filter((_, i) => i !== index)
        },

        dismissConflicts() {
            this.conflicts = []
        },

        stateValue() {
            return this.statePath.split('.').filter(Boolean).reduce(
                (value, key) => value?.[key],
                this.$wire,
            )
        },

        async runWireAction(method) {
            clearTimeout(this.timer)
            clearTimeout(this.fadeTimer)
            this.status = statuses.saving

            try {
                await this.$wire[method]()
                this.resolvePending()
            } catch (e) {
                this.setStatus(statuses.error)
            }
        },

        async undo() {
            this.runWireAction('undoAutosave')
        },

        async restore() {
            this.runWireAction('restoreDraft')
        },

        // Certain server actions come back without emitting a status event.
        resolvePending() {
            if (this.status === statuses.saving) {
                this.status = statuses.idle
            }
        },

        async discard() {
            clearTimeout(this.timer)
            clearTimeout(this.fadeTimer)

            try {
                await this.$wire.discardDraft()
            } catch (e) {
                this.setStatus(statuses.error)
            }
        },

        setStatus(newStatus, newTimestamp = null, newErrors = {}, newRefreshedState = {}, newPendingFields = [], newStaleFields = []) {
            if (this.destroyed) {
                return
            }

            // A poll result must not clobber an in-progress save's status.
            if (newStatus === statuses.synced && (this.savePending || this.status === statuses.saving)) {
                return
            }

            // "Unchanged" (idle) only ever replaces a transient state. While a
            // fresh settled badge is still showing -- or a redundant save is
            // in flight on top of one -- keep that badge instead: the second
            // save after an upload, or a hash-watcher replay, must not blank
            // out "saved" milliseconds after it appeared. An explicit action
            // (undo/restore) sets saving without savePending, so its idle
            // reply still lands.
            if (newStatus === statuses.idle && this.holdsFreshResult()
                && (this.status === this.heldResult.status || this.savePending)) {
                this.restoreHeldResult()

                return
            }

            clearTimeout(this.fadeTimer)

            this.status = newStatus
            this.timestamp = newTimestamp
            this.validationErrors = newErrors || {}
            this.refreshedState = newRefreshedState || {}
            this.pendingFields = newPendingFields || []
            this.staleFields = newStaleFields || []

            const fadeMs = this.fadeMsByStatus[newStatus]
            this.heldResult = null

            if (!this.cancelled && fadeMs) {
                this.heldResult = { status: newStatus, until: Date.now() + fadeMs }
                this.fadeTimer = setTimeout(() => {
                    this.status = statuses.idle
                    this.heldResult = null
                }, fadeMs)
            }

            if (newStatus === statuses.synced) {
                this.absorbRefreshedIntoBaseline()

                return
            }

            if (this.isSettled(newStatus)) {
                this.rememberBaseline()
            }
        },

        // A poll refilled clean fields from the server: fold only those paths
        // into the baseline so they do not read as local edits, and leave any
        // genuinely dirty field exactly as dirty as it was.
        absorbRefreshedIntoBaseline() {
            let baseline

            try {
                baseline = JSON.parse(this.baselineJson)
            } catch (e) {
                baseline = this.stateValue()
            }

            for (const [path, value] of Object.entries(this.refreshedState)) {
                this.setStatePath(baseline, path, value)
            }

            this.baselineJson = JSON.stringify(baseline)

            // What the live state looks like once Livewire has applied the
            // refill: the current state (dirty fields included) with the
            // refreshed paths overlaid. Both watchers treat exactly that
            // value as "not a user edit".
            const expected = JSON.parse(JSON.stringify(this.stateValue()))

            for (const [path, value] of Object.entries(this.refreshedState)) {
                this.setStatePath(expected, path, value)
            }

            this.lastSyncedStateJson = JSON.stringify(expected)
        },

        // The server state is the baseline: which Wire state counts as already saved.
        rememberBaseline() {
            if (this.savePending && this.isSaveResult(this.status)) {
                let baseline

                try {
                    baseline = JSON.parse(this.sentJson)
                } catch (e) {
                    baseline = this.stateValue()
                }

                for (const [path, value] of Object.entries(this.refreshedState)) {
                    this.setStatePath(baseline, path, value)
                }

                this.serverBaselineJson = JSON.stringify(baseline)
                this.baselineJson = this.serverBaselineJson

                return
            }

            this.baselineJson = JSON.stringify(this.stateValue())
        },

        setStatePath(state, path, value) {
            const keys = path.split('.').filter(Boolean)

            if (!keys.length) {
                return
            }

            let target = state

            for (const key of keys.slice(0, -1)) {
                if (!target[key] || typeof target[key] !== 'object') {
                    target[key] = {}
                }

                target = target[key]
            }

            target[keys[keys.length - 1]] = value
        },

        // Statuses that leave the form aligned with the server state.
        isSettled(status) {
            return statuses.settled.includes(status)
        },

        // Statuses a save() request can finish with, where the sent payload wins.
        isSaveResult(status) {
            return statuses.saveResults.includes(status)
        },

        fadeMsByStatus: statuses.fadeMs,

        destroy() {
            this.destroyed = true
            clearTimeout(this.timer)
            clearTimeout(this.fadeTimer)
            clearTimeout(this.pollTimer)
            if (this._visibilityHandler) {
                document.removeEventListener('visibilitychange', this._visibilityHandler)
            }
            if (this._unloadHandler) {
                window.removeEventListener('beforeunload', this._unloadHandler)
            }
            document.removeEventListener('submit', this._submitHandler)
            document.removeEventListener('livewire-upload-start', this._uploadStart)
            for (const event of ['livewire-upload-finish', 'livewire-upload-error', 'livewire-upload-cancel']) {
                document.removeEventListener(event, this._uploadEnd)
            }
            this._offStatus?.()
        },
    }
})({ debounce: {{ (int) $debounce }}, mode: @js($mode), statuses: @js($statusMeta ?? []), mergeFields: @js(array_values($mergeFields ?? [])) })
