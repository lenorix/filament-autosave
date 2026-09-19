(function ({ debounce = 1500, mode = 'edit', statuses = {} }) {
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

        init() {
            if (this.$wire.autosaveEnabled === false) {
                return
            }

            // Precedence across page, plugin and config is resolved on the PHP side.
            this.debounceMs = Number(this.$wire.autosaveDebounceMs) || debounce
            this.statePath = this.$wire.autosaveDataPath || 'data'
            this.cancelled = false

            this.rememberBaseline()

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
                this.setStatus(data.status, data.timestamp || null, data.errors || {}, data.refreshed || {}, data.pending || [], data.stale || [])
            })

            this.pollMs = Number(this.$wire.autosavePollMs) || 0

            if (this.pollMs > 0) {
                this._visibilityHandler = () => {
                    if (document.visibilityState === 'visible') {
                        this.schedulePoll(0)
                    } else {
                        clearTimeout(this.pollTimer)
                    }
                }
                document.addEventListener('visibilitychange', this._visibilityHandler)
                this.schedulePoll()
            }

            if (mode === 'edit') {
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
            if (this.uploadsPending || this.savePending || this.destroyed || this.status === statuses.saving) {
                return
            }

            this.savePending = true
            this.sentJson = JSON.stringify(this.stateValue())
            this.serverBaselineJson = this.sentJson
            this.refreshedState = {}
            this.status = statuses.saving

            try {
                await this.$wire.autosave()
            } catch (e) {
                this.setStatus(statuses.error)
            } finally {
                const changedDuringSave = JSON.stringify(this.stateValue()) !== (this.serverBaselineJson || this.sentJson)
                this.savePending = false
                this.sentJson = null
                this.serverBaselineJson = null
                this.refreshedState = {}

                if (!this.destroyed && !this.cancelled && changedDuringSave && this.status !== statuses.error) {
                    this.onDataChanged()
                }
            }
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
                await this.$wire.syncAutosave()
                this.pollErrors = 0
            } catch (e) {
                this.pollErrors++
            } finally {
                this.pollInFlight = false
                this.schedulePoll()
            }
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

            clearTimeout(this.fadeTimer)

            this.status = newStatus
            this.timestamp = newTimestamp
            this.validationErrors = newErrors || {}
            this.refreshedState = newRefreshedState || {}
            this.pendingFields = newPendingFields || []
            this.staleFields = newStaleFields || []

            const fadeMs = this.fadeMsByStatus[newStatus]

            if (!this.cancelled && fadeMs) {
                this.fadeTimer = setTimeout(() => {
                    this.status = statuses.idle
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
            document.removeEventListener('submit', this._submitHandler)
            document.removeEventListener('livewire-upload-start', this._uploadStart)
            for (const event of ['livewire-upload-finish', 'livewire-upload-error', 'livewire-upload-cancel']) {
                document.removeEventListener(event, this._uploadEnd)
            }
            this._offStatus?.()
        },
    }
})({ debounce: {{ (int) $debounce }}, mode: @js($mode), statuses: @js($statusMeta ?? []) })
