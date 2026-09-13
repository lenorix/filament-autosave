(function ({ debounce = 1500, mode = 'edit', statuses = {} }) {
    return {
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
        serverBaselineJson: null,
        destroyed: false,
        cancelled: false,
        mode: mode,
        debounceMs: debounce,
        statePath: 'data',

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

                    this.cancelled = false
                    this.onDataChanged()
                },
            )

            this._offStatus = this.$wire.$on(statuses.event, (params) => {
                const data = Array.isArray(params) ? params[0] : params
                this.setStatus(data.status, data.timestamp || null, data.errors || {}, data.refreshed || {}, data.pending || [])
            })

            if (mode === 'edit') {
                this.$watch(() => this.$wire.autosaveObservedHash, () => {
                    if (!this.savePending && JSON.stringify(this.stateValue()) !== this.baselineJson) {
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

        setStatus(newStatus, newTimestamp = null, newErrors = {}, newRefreshedState = {}, newPendingFields = []) {
            if (this.destroyed) {
                return
            }

            clearTimeout(this.fadeTimer)

            this.status = newStatus
            this.timestamp = newTimestamp
            this.validationErrors = newErrors || {}
            this.refreshedState = newRefreshedState || {}
            this.pendingFields = newPendingFields || []

            const fadeMs = this.fadeMsByStatus[newStatus]

            if (!this.cancelled && fadeMs) {
                this.fadeTimer = setTimeout(() => {
                    this.status = statuses.idle
                }, fadeMs)
            }

            if (this.isSettled(newStatus)) {
                this.rememberBaseline()
            }
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
            document.removeEventListener('submit', this._submitHandler)
            document.removeEventListener('livewire-upload-start', this._uploadStart)
            for (const event of ['livewire-upload-finish', 'livewire-upload-error', 'livewire-upload-cancel']) {
                document.removeEventListener(event, this._uploadEnd)
            }
            this._offStatus?.()
        },
    }
})({ debounce: {{ (int) $debounce }}, mode: @js($mode), statuses: @js($statusMeta ?? []) })
