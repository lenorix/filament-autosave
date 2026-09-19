/*
 * Browser side of merging plain-text fields two editors type in at once.
 *
 * Dependency-free. Mirrors src/AutosaveTextMerge.php: the same word
 * tokenizer, the same Myers diff, the same diff3 rules (non-overlapping
 * hunks from both sides are kept; an overlapping range keeps ours) and the
 * same diff-match-patch patch text, so a patch built here applies on the
 * server exactly as one built there. Everything works on arrays of code
 * points, never UTF-16 units, so offsets agree with PHP even around emoji.
 *
 * Three parts, kept apart so a real-time transport can reuse the last two:
 *
 * - engine: tokenize, diff, merge, makePatch, mapOffset (pure functions);
 * - sync:   what to send with a save or a poll, and what a payload means
 *           for the values the browser holds (its bases and hashes);
 * - apply:  put a new value into an input without losing the caret.
 *
 * Loaded once per page by the indicator view; idempotent under a re-render.
 */
window.FilamentAutosaveMerge = window.FilamentAutosaveMerge || (function () {
    'use strict'

    const EQUAL = 0
    const DELETE = -1
    const INSERT = 1
    const PATCH_MARGIN = 4
    const MATCH_MAX_BITS = 32
    const MAX_EDIT_DISTANCE = 1000
    const OVERLAP = 'overlap'
    const CONTENDED = 'contended'

    // ------------------------------------------------------------------
    // Engine
    // ------------------------------------------------------------------

    const chars = (text) => (text === '' ? [] : Array.from(text))

    function tokenize(text) {
        return text === '' ? [] : (text.match(/\s+|[\p{L}\p{M}\p{N}_]+|./gsu) || [])
    }

    function diff(a, b) {
        const n = a.length
        const m = b.length
        let prefix = 0

        while (prefix < n && prefix < m && a[prefix] === b[prefix]) {
            prefix++
        }

        let suffix = 0

        while (suffix < n - prefix && suffix < m - prefix && a[n - 1 - suffix] === b[m - 1 - suffix]) {
            suffix++
        }

        const ops = []

        if (prefix > 0) {
            ops.push([EQUAL, a.slice(0, prefix)])
        }

        ops.push(...myers(a.slice(prefix, n - suffix), b.slice(prefix, m - suffix)))

        if (suffix > 0) {
            ops.push([EQUAL, a.slice(n - suffix)])
        }

        return ops
    }

    function myers(a, b) {
        const n = a.length
        const m = b.length

        if (n === 0) {
            return m === 0 ? [] : [[INSERT, b]]
        }

        if (m === 0) {
            return [[DELETE, a]]
        }

        const max = Math.min(n + m, MAX_EDIT_DISTANCE)
        let v = { 1: 0 }
        const trace = []
        let found = false

        for (let d = 0; d <= max && !found; d++) {
            trace.push({ ...v })

            for (let k = -d; k <= d; k += 2) {
                let x = (k === -d || (k !== d && v[k - 1] < v[k + 1])) ? v[k + 1] : v[k - 1] + 1
                let y = x - k

                while (x < n && y < m && a[x] === b[y]) {
                    x++
                    y++
                }

                v[k] = x

                if (x >= n && y >= m) {
                    found = true
                    break
                }
            }
        }

        if (!found) {
            return [[DELETE, a], [INSERT, b]]
        }

        const steps = []
        let x = n
        let y = m

        for (let d = trace.length - 1; d >= 0; d--) {
            v = trace[d]
            const k = x - y
            const previousK = (k === -d || (k !== d && v[k - 1] < v[k + 1])) ? k + 1 : k - 1
            const previousX = v[previousK]
            const previousY = previousX - previousK

            while (x > previousX && y > previousY) {
                steps.push([EQUAL, a[x - 1]])
                x--
                y--
            }

            if (d > 0) {
                steps.push(x === previousX ? [INSERT, b[previousY]] : [DELETE, a[previousX]])
            }

            x = previousX
            y = previousY
        }

        return groupSteps(steps.reverse())
    }

    function groupSteps(steps) {
        const runs = []

        for (const [op, token] of steps) {
            const last = runs[runs.length - 1]

            if (last && last[0] === op) {
                last[1].push(token)
            } else {
                runs.push([op, [token]])
            }
        }

        const ops = []
        const changed = { [DELETE]: [], [INSERT]: [] }

        for (const [op, tokens] of [...runs, [EQUAL, []]]) {
            if (op !== EQUAL) {
                changed[op].push(...tokens)
                continue
            }

            for (const kind of [DELETE, INSERT]) {
                if (changed[kind].length) {
                    ops.push([kind, changed[kind]])
                    changed[kind] = []
                }
            }

            if (tokens.length) {
                ops.push([EQUAL, tokens])
            }
        }

        return ops
    }

    function hunks(base, side, name) {
        const result = []
        let index = 0
        let open = null

        for (const [op, tokens] of diff(base, side)) {
            if (op === EQUAL) {
                if (open) {
                    result.push(open)
                    open = null
                }

                index += tokens.length
                continue
            }

            open = open || { start: index, end: index, tokens: [], side: name }

            if (op === DELETE) {
                open.end += tokens.length
                index += tokens.length
            } else {
                open.tokens.push(...tokens)
            }
        }

        if (open) {
            result.push(open)
        }

        return result
    }

    function overlaps(a, b) {
        if (Math.max(a.start, b.start) < Math.min(a.end, b.end)) {
            return true
        }

        return (b.start === b.end && a.start < b.start && b.start < a.end)
            || (a.start === a.end && b.start < a.start && a.start < b.end)
    }

    function groupOverlapping(list) {
        const groups = []
        let group = null

        for (const hunk of list) {
            if (group && overlaps(group, hunk)) {
                group.end = Math.max(group.end, hunk.end)
                group.hunks.push(hunk)
                continue
            }

            if (group) {
                groups.push(group)
            }

            group = { start: hunk.start, end: hunk.end, hunks: [hunk] }
        }

        if (group) {
            groups.push(group)
        }

        return groups
    }

    function applyHunks(base, group, side) {
        let output = ''
        let cursor = group.start

        for (const hunk of group.hunks) {
            if (hunk.side !== side) {
                continue
            }

            output += base.slice(cursor, hunk.start).join('') + hunk.tokens.join('')
            cursor = hunk.end
        }

        return output + base.slice(cursor, group.end).join('')
    }

    const length = (text) => chars(text).length

    /**
     * Combine two edits of `base`; an overlapping range keeps `ours` and is
     * reported with the text of theirs it discarded. Positions are
     * code-point offsets into the merged value.
     */
    function merge(base, ours, theirs) {
        if (ours === theirs) {
            return { value: ours, conflicts: [] }
        }

        const baseTokens = tokenize(base)
        const all = [...hunks(baseTokens, tokenize(ours), 'ours'), ...hunks(baseTokens, tokenize(theirs), 'theirs')]

        all.sort((a, b) => (a.start - b.start)
            || ((a.end > a.start ? 1 : 0) - (b.end > b.start ? 1 : 0))
            || (a.side < b.side ? -1 : a.side > b.side ? 1 : 0))

        let output = ''
        let position = 0
        let cursor = 0
        let previousInsertion = null
        const conflicts = []

        for (const group of groupOverlapping(all)) {
            const equal = baseTokens.slice(cursor, group.start).join('')
            output += equal
            position += length(equal)
            const sides = [...new Set(group.hunks.map((hunk) => hunk.side))]
            const oursText = applyHunks(baseTokens, group, 'ours')
            const theirsText = applyHunks(baseTokens, group, 'theirs')
            const resolved = sides.includes('ours') ? oursText : theirsText

            if (group.start === group.end && previousInsertion === group.start
                && resolved !== '' && output !== '' && !/\s$/u.test(output) && !/^\s/u.test(resolved)) {
                output += ' '
                position++
            }

            if (sides.length === 2 && oursText !== theirsText) {
                conflicts.push({ ours: oursText, theirs: theirsText, position })
            }

            output += resolved
            position += length(resolved)
            cursor = group.end
            previousInsertion = group.start === group.end ? group.start : null
        }

        output += baseTokens.slice(cursor).join('')

        return { value: output, conflicts }
    }

    // --- Patches (diff-match-patch text format) ------------------------------

    function occursOnce(text, pattern) {
        if (!pattern.length) {
            return !text.length
        }

        const haystack = text.join('')
        const needle = pattern.join('')

        return haystack.indexOf(needle) === haystack.lastIndexOf(needle)
    }

    function addContext(patch, text) {
        if (!text.length) {
            return patch
        }

        let pattern = text.slice(patch.start2, patch.start2 + patch.length1)
        let padding = 0

        while (!occursOnce(text, pattern) && pattern.length < MATCH_MAX_BITS - 2 * PATCH_MARGIN) {
            padding += PATCH_MARGIN
            const from = Math.max(0, patch.start2 - padding)
            pattern = text.slice(from, patch.start2 + patch.length1 + padding)
        }

        padding += PATCH_MARGIN
        const from = Math.max(0, patch.start2 - padding)
        const prefix = text.slice(from, patch.start2)
        const suffix = text.slice(patch.start2 + patch.length1, patch.start2 + patch.length1 + padding)

        if (prefix.length) {
            patch.diffs.unshift([EQUAL, prefix])
        }

        if (suffix.length) {
            patch.diffs.push([EQUAL, suffix])
        }

        patch.start1 -= prefix.length
        patch.start2 -= prefix.length
        patch.length1 += prefix.length + suffix.length
        patch.length2 += prefix.length + suffix.length

        return patch
    }

    function coordinates(start, length) {
        if (length === 0) {
            return start + ',0'
        }

        return length === 1 ? String(start + 1) : (start + 1) + ',' + length
    }

    function patchesToText(patches) {
        let text = ''

        for (const patch of patches) {
            text += '@@ -' + coordinates(patch.start1, patch.length1) + ' +' + coordinates(patch.start2, patch.length2) + ' @@\n'

            for (const [op, run] of patch.diffs) {
                text += (op === INSERT ? '+' : op === DELETE ? '-' : ' ') + encodeURI(run.join('')).replace(/%20/g, ' ') + '\n'
            }
        }

        return text
    }

    /**
     * Patch text turning `before` into `after`: word-level hunks, code-point
     * offsets, context margins. Empty when nothing changed.
     */
    function makePatch(before, after) {
        if (before === after) {
            return ''
        }

        const diffs = diff(tokenize(before), tokenize(after)).map(([op, tokens]) => [op, chars(tokens.join(''))])
        const patches = []
        let patch = null
        let count1 = 0
        let count2 = 0
        let prepatch = chars(before)
        const postpatch = chars(before)
        const last = diffs.length - 1

        diffs.forEach(([op, run], index) => {
            const size = run.length

            if (!patch && op !== EQUAL) {
                patch = { diffs: [], start1: count1, start2: count2, length1: 0, length2: 0 }
            }

            if (op === INSERT) {
                patch.diffs.push([op, run])
                patch.length2 += size
                postpatch.splice(count2, 0, ...run)
            } else if (op === DELETE) {
                patch.diffs.push([op, run])
                patch.length1 += size
                postpatch.splice(count2, size)
            } else if (size <= 2 * PATCH_MARGIN && patch && index !== last) {
                patch.diffs.push([op, run])
                patch.length1 += size
                patch.length2 += size
            } else if (size >= 2 * PATCH_MARGIN && patch) {
                patches.push(addContext(patch, prepatch))
                patch = null
                prepatch = postpatch.slice()
                count1 = count2
            }

            if (op !== INSERT) {
                count1 += size
            }

            if (op !== DELETE) {
                count2 += size
            }
        })

        if (patch) {
            patches.push(addContext(patch, prepatch))
        }

        return patchesToText(patches)
    }

    // --- Caret mapping ---------------------------------------------------------

    const toCodePoints = (text, utf16Offset) => Array.from(text.slice(0, utf16Offset)).length

    const toUtf16 = (text, codePointOffset) => Array.from(text).slice(0, codePointOffset).join('').length

    /**
     * Where a UTF-16 offset into `before` lands in `after`: an offset inside
     * an unchanged run moves with it; one inside a changed run lands at the
     * end of that run's replacement, so a caret never jumps backwards over
     * text the other editor inserted at its place.
     */
    function mapOffset(before, after, offset) {
        const target = toCodePoints(before, offset)
        let from = 0
        let to = 0

        for (const [op, tokens] of diff(tokenize(before), tokenize(after))) {
            const size = length(tokens.join(''))

            if (op === EQUAL) {
                if (target <= from + size) {
                    return toUtf16(after, to + (target - from))
                }

                from += size
                to += size
            } else if (op === DELETE) {
                if (target < from + size) {
                    return toUtf16(after, to)
                }

                from += size
            } else {
                to += size
            }
        }

        return toUtf16(after, to)
    }

    const engine = { tokenize, diff, merge, makePatch, mapOffset, toUtf16, OVERLAP, CONTENDED }

    // ------------------------------------------------------------------
    // Sync: bases, hashes, and what a payload means
    // ------------------------------------------------------------------

    const text = (value) => (value === null || value === undefined ? '' : String(value))

    /**
     * Holds, per mergeable field, the last value the server is known to
     * have (the base every patch is built from) and, when the server named
     * it, that value's hash, echoed back so a poll can skip it. A base only
     * ever advances to a value the input reflects.
     */
    function createSync(fields) {
        const bases = {}
        const hashes = {}

        return {
            fields,

            has(path) {
                return Object.prototype.hasOwnProperty.call(bases, path)
            },

            base(path) {
                return bases[path]
            },

            // Mount, or a value the server wrote/refilled: known, unhashed.
            acknowledge(path, value, hash = null) {
                bases[path] = text(value)

                if (hash) {
                    hashes[path] = hash
                } else {
                    delete hashes[path]
                }
            },

            seed(values) {
                for (const path of fields) {
                    if (!this.has(path)) {
                        this.acknowledge(path, values?.[path])
                    }
                }
            },

            // The fields were rewritten outside a merge (undo, restore):
            // what they hold now is what the server has.
            resync(values) {
                for (const path of fields) {
                    this.acknowledge(path, values?.[path])
                }
            },

            // `autosave(mergePatches)`: one patch per dirty mergeable field.
            patches(values) {
                const patches = {}

                for (const path of fields) {
                    if (!this.has(path)) {
                        continue
                    }

                    const patch = makePatch(bases[path], text(values?.[path]))

                    if (patch !== '') {
                        patches[path] = patch
                    }
                }

                return patches
            },

            // `syncAutosave(mergeBaseHashes)`: only hashes the server handed us.
            baseHashes() {
                return { ...hashes }
            },

            /**
             * Fold a payload into the bases and say what each input should
             * now hold. `live` is the values as the inputs have them, `sent`
             * the values a save carried (null for a poll). Returns
             * `{ updates: {path: value}, conflicts: [{path, ours, theirs, position, reason}] }`.
             */
            receive(payload, live, sent = null) {
                const updates = {}
                const conflicts = []
                const merged = payload?.merged || {}
                const patches = payload?.patches || {}
                const reported = payload?.conflicts || {}
                const pending = Array.isArray(payload?.pending) ? payload.pending : []
                const refreshed = payload?.refreshed || {}

                for (const path of fields) {
                    if (!this.has(path)) {
                        continue
                    }

                    const ours = text(live?.[path])

                    // A value the server refilled is the base and the input alike.
                    if (Object.prototype.hasOwnProperty.call(refreshed, path)) {
                        this.acknowledge(path, refreshed[path])
                        continue
                    }

                    if (Object.prototype.hasOwnProperty.call(merged, path)) {
                        const contended = Object.prototype.hasOwnProperty.call(patches, path)
                        const value = text(merged[path])
                        // Our patch was played on their value: adopt the result on
                        // top of whatever was typed while the request ran.
                        const result = merge(text(sent?.[path] ?? bases[path]), ours, value)

                        if (result.value !== ours) {
                            updates[path] = result.value
                        }

                        for (const conflict of reported[path] || []) {
                            conflicts.push({ path, ...conflict })
                        }

                        contended
                            ? this.acknowledge(path, patches[path].theirs, patches[path].hash)
                            : this.acknowledge(path, value)

                        continue
                    }

                    if (Object.prototype.hasOwnProperty.call(patches, path)) {
                        // A poll: their current value against our unsaved edit.
                        const theirs = text(patches[path].theirs)
                        const result = merge(bases[path], ours, theirs)

                        if (result.value !== ours) {
                            updates[path] = result.value
                        }

                        for (const conflict of result.conflicts) {
                            conflicts.push({ path, ...conflict, reason: OVERLAP })
                        }

                        this.acknowledge(path, theirs, patches[path].hash)
                        continue
                    }

                    // A clean write of what we sent, nothing merged.
                    if (sent && Object.prototype.hasOwnProperty.call(sent, path) && !pending.includes(path)) {
                        for (const conflict of reported[path] || []) {
                            conflicts.push({ path, ...conflict })
                        }

                        this.acknowledge(path, sent[path])
                    }
                }

                return { updates, conflicts }
            },
        }
    }

    // ------------------------------------------------------------------
    // Apply: new value into an input, caret intact
    // ------------------------------------------------------------------

    /** The input bound to a Livewire state path inside a component root. */
    function findInput(root, statePath) {
        for (const el of (root || document).querySelectorAll('input, textarea')) {
            for (const name of el.getAttributeNames()) {
                if (name.startsWith('wire:model') && el.getAttribute(name) === statePath) {
                    return el
                }
            }
        }

        return null
    }

    /**
     * Replace an input's value keeping the caret (or selection) on the text
     * it was on. `previous` may carry the value and selection captured
     * earlier (before a request) when the input has since been rewritten by
     * a server round trip. Fires `input` so the bound state follows.
     */
    function toInput(el, value, previous = null) {
        const current = el.value
        const focused = document.activeElement === el
        let from = current
        let start = el.selectionStart ?? current.length
        let end = el.selectionEnd ?? start

        // The round trip already rewrote the input (caret thrown to the
        // end): the caret to map is the one captured before the request.
        if (previous && current !== previous.value && current === value) {
            from = previous.value
            start = previous.start
            end = previous.end
        }

        if (current !== value) {
            el.value = value
        }

        if (focused && from !== value) {
            const newStart = mapOffset(from, value, start)
            el.setSelectionRange(newStart, end === start ? newStart : mapOffset(from, value, end))
        }

        if (current !== value) {
            el.dispatchEvent(new Event('input', { bubbles: true }))
        }
    }

    /** Snapshot of an input for a later `toInput()`. */
    function snapshot(el) {
        return el ? { value: el.value, start: el.selectionStart ?? el.value.length, end: el.selectionEnd ?? el.value.length } : null
    }

    /**
     * Put the other editor's discarded text back: replace ours at the
     * reported position when it is still there, otherwise insert theirs at
     * that position. Returns the new value.
     */
    function recover(value, conflict) {
        const ours = text(conflict.ours)
        const theirs = text(conflict.theirs)
        const at = toUtf16(value, Math.max(0, Number(conflict.position) || 0))

        if (ours !== '' && value.slice(at, at + ours.length) === ours) {
            return value.slice(0, at) + theirs + value.slice(at + ours.length)
        }

        const found = ours !== '' ? value.indexOf(ours) : -1

        if (found !== -1) {
            return value.slice(0, found) + theirs + value.slice(found + ours.length)
        }

        const position = Math.min(at, value.length)
        const glue = position > 0 && !/\s$/u.test(value.slice(0, position)) && !/^\s/u.test(theirs) ? ' ' : ''

        return value.slice(0, position) + glue + theirs + value.slice(position)
    }

    const apply = { findInput, toInput, snapshot, recover }

    return { engine, createSync, apply }
})()
