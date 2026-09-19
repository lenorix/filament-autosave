/*
 * Browser side of the structural RichEditor merge (see AutosaveRichMerge.php).
 *
 * Nothing is bundled: the ProseMirror modules come from the editor Filament
 * already put on the page (window.FilamentRichEditor.tiptap), so a merged
 * document is applied to the live editor as one transaction and the caret is
 * mapped through it like any other edit. Three parts, kept apart so a push
 * transport can reuse them later:
 *
 * - docs:   turn whatever the server sent (HTML, a JSON string, a doc object)
 *           into a ProseMirror node of the editor's own schema.
 * - merge:  block-level three-way merge used when the reply lands on top of
 *           text typed while the request ran (the server never sees that).
 * - apply:  put a document into the editor changing only the blocks that
 *           differ, or put a discarded fragment back where it was.
 */
window.FilamentAutosaveRichMerge = window.FilamentAutosaveRichMerge || (function () {
    'use strict'

    const tiptap = () => window.FilamentRichEditor?.tiptap
    const REMOTE = 'autosaveRemote'

    // ------------------------------------------------------------------
    // Finding the editor bound to a state path
    // ------------------------------------------------------------------

    /**
     * The element carrying Filament's rich editor component for a state
     * path: its `x-data` names the path. (No regular expression and no
     * escaped backslash anywhere in this file: the script is inlined
     * through Livewire's asset pipeline, which halves them.)
     */
    function element(root, statePath) {
        for (const el of (root || document).querySelectorAll('[x-data]')) {
            const data = el.getAttribute('x-data') || ''

            if (!data.startsWith('richEditorFormComponent(')) {
                continue
            }

            if (data.includes("statePath: '" + statePath + "'") || data.includes('statePath: "' + statePath + '"')) {
                return el
            }
        }

        return null
    }

    /**
     * The rich editor whose state is bound to `statePath`, as
     * `{ el, data, editor }`, or null. `data` is Filament's Alpine
     * component (state, shouldUpdateState, getEditor).
     */
    function find(root, statePath) {
        const el = element(root, statePath)

        if (!el || !window.Alpine || !tiptap()) {
            return null
        }

        const data = window.Alpine.$data(el)
        const editor = typeof data?.getEditor === 'function' ? data.getEditor() : null

        return editor ? { el, data, editor } : null
    }

    // ------------------------------------------------------------------
    // Documents
    // ------------------------------------------------------------------

    function parseHtml(editor, html) {
        const dom = document.implementation.createHTMLDocument('').body
        dom.innerHTML = html
        return tiptap().pmModel.DOMParser.fromSchema(editor.schema).parse(dom)
    }

    /** A ProseMirror doc node from a doc object, a JSON string or HTML. */
    function toDoc(editor, value) {
        if (value === null || value === undefined || value === '') {
            return editor.schema.topNodeType.createAndFill()
        }

        if (typeof value === 'object') {
            return editor.schema.nodeFromJSON(value)
        }

        const trimmed = String(value).trim()

        if (trimmed.startsWith('{')) {
            try {
                return editor.schema.nodeFromJSON(JSON.parse(trimmed))
            } catch (e) {
                // Not JSON after all: parse it as HTML below.
            }
        }

        return parseHtml(editor, trimmed)
    }

    /** The nodes of a conflict fragment (HTML, or a list of node objects). */
    function toNodes(editor, fragment) {
        if (fragment === null || fragment === undefined || fragment === '') {
            return []
        }

        if (Array.isArray(fragment)) {
            return fragment.map((node) => editor.schema.nodeFromJSON(node))
        }

        if (typeof fragment === 'object') {
            return [editor.schema.nodeFromJSON(fragment)]
        }

        const nodes = []
        parseHtml(editor, String(fragment)).forEach((node) => nodes.push(node))
        return nodes
    }

    /** JSON the editor would report for this value: the base the server compares with. */
    function normalize(editor, value) {
        return JSON.stringify(toDoc(editor, value).toJSON())
    }

    /** The text the server's conflict positions index into. */
    function plainText(node) {
        const blocks = []

        const walk = (n) => {
            if (n.isTextblock) {
                let text = ''
                n.forEach((child) => {
                    text += child.isText ? child.text : (child.type.name === 'hardBreak' ? '\n' : '')
                })
                blocks.push(text)
                return
            }

            if (n.isLeaf) {
                blocks.push('')
                return
            }

            n.forEach(walk)
        }

        node.forEach(walk)

        return blocks.join('\n')
    }

    /** A short readable preview of a fragment, for the conflicts callout. */
    function preview(editor, fragment, limit = 120) {
        const nodes = toNodes(editor, fragment)
        const parts = []

        for (const node of nodes) {
            parts.push(node.isTextblock || node.isText ? node.textContent : node.type.name === 'image' ? '[' + (node.attrs.alt || 'image') + ']' : node.textContent || '[' + node.type.name + ']')
        }

        const text = parts.join(' ').replace(/\s+/g, ' ').trim()

        return text.length > limit ? text.slice(0, limit - 1) + '…' : text
    }

    // ------------------------------------------------------------------
    // Block-level three-way merge (for text typed while a request ran)
    // ------------------------------------------------------------------

    const key = (node) => JSON.stringify(node.toJSON())

    function children(node) {
        const list = []
        node.forEach((child) => list.push(child))
        return list
    }

    /** Hunks of `side` against `base`, both as lists of block nodes. */
    function blockHunks(base, side, name) {
        const engine = window.FilamentAutosaveMerge.engine
        const result = []
        let index = 0
        let open = null
        const sideByKey = new Map()
        side.forEach((node) => sideByKey.set(key(node), node))

        for (const [op, keys] of engine.diff(base.map(key), side.map(key))) {
            if (op === 0) {
                if (open) {
                    result.push(open)
                    open = null
                }
                index += keys.length
                continue
            }

            open = open || { start: index, end: index, nodes: [], side: name }

            if (op === -1) {
                open.end += keys.length
                index += keys.length
            } else {
                open.nodes.push(...keys.map((k) => sideByKey.get(k)))
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

    /**
     * Combine two edits of `base` at block granularity: blocks changed on
     * one side only are taken from that side, insertions from both are
     * kept, and a block changed on both sides is taken from `winner`
     * ('ours' or 'theirs'). Returns a doc node.
     */
    function mergeBlocks(base, ours, theirs, winner = 'theirs') {
        const baseBlocks = children(base)
        const all = [...blockHunks(baseBlocks, children(ours), 'ours'), ...blockHunks(baseBlocks, children(theirs), 'theirs')]

        all.sort((a, b) => (a.start - b.start)
            || ((a.end > a.start ? 1 : 0) - (b.end > b.start ? 1 : 0))
            || (a.side < b.side ? -1 : a.side > b.side ? 1 : 0))

        const output = []
        let cursor = 0
        let group = null
        const groups = []

        for (const hunk of all) {
            if (group && overlaps(group, hunk)) {
                group.end = Math.max(group.end, hunk.end)
                group.hunks.push(hunk)
                continue
            }

            group = { start: hunk.start, end: hunk.end, hunks: [hunk] }
            groups.push(group)
        }

        for (const g of groups) {
            output.push(...baseBlocks.slice(cursor, g.start))
            const sides = new Set(g.hunks.map((h) => h.side))
            const keep = sides.size === 2 ? winner : [...sides][0]
            let at = g.start

            for (const hunk of g.hunks) {
                if (hunk.side !== keep) {
                    continue
                }

                output.push(...baseBlocks.slice(at, hunk.start), ...hunk.nodes)
                at = hunk.end
            }

            output.push(...baseBlocks.slice(at, g.end))
            cursor = g.end
        }

        output.push(...baseBlocks.slice(cursor))

        return base.type.create(base.attrs, output.length ? output : undefined)
    }

    // ------------------------------------------------------------------
    // Apply: minimal replacements in one transaction
    // ------------------------------------------------------------------

    /** Positions of each top-level child: [start, end] with start = pos before the node. */
    function offsets(doc) {
        const list = []
        doc.forEach((node, pos) => list.push([pos, pos + node.nodeSize]))
        return list
    }

    /**
     * Steps that turn the editor's document into `target`, touching only the
     * ranges that differ: unchanged top-level blocks are left alone, a
     * changed text block is replaced from the first differing character to
     * the last. `before(docJson)` runs with the resulting document before
     * the transaction is dispatched. Returns false when nothing changed.
     */
    function toEditor(editor, target, { remote = true, before = null } = {}) {
        const { state } = editor
        const current = state.doc
        const engine = window.FilamentAutosaveMerge.engine

        if (current.eq(target)) {
            return false
        }

        const cur = children(current)
        const next = children(target)
        const curPos = offsets(current)
        const nextPos = offsets(target)
        const runs = []
        let i = 0
        let j = 0

        for (const [op, keys] of engine.diff(cur.map(key), next.map(key))) {
            if (op === 0) {
                i += keys.length
                j += keys.length
                continue
            }

            const last = runs[runs.length - 1]

            if (last && last.i1 === i && last.j1 === j) {
                if (op === -1) last.i1 += keys.length
                else last.j1 += keys.length
                continue
            }

            runs.push({ i0: i, i1: i + (op === -1 ? keys.length : 0), j0: j, j1: j + (op === 1 ? keys.length : 0) })

            if (op === -1) i += keys.length
            else j += keys.length
        }

        let tr = state.tr

        // Back to front so earlier positions stay valid without mapping.
        for (const run of runs.reverse()) {
            const from = run.i0 < cur.length ? curPos[run.i0][0] : current.content.size
            const to = run.i1 > run.i0 ? curPos[run.i1 - 1][1] : from
            const single = run.i1 - run.i0 === 1 && run.j1 - run.j0 === 1
                && cur[run.i0].isTextblock && next[run.j0].isTextblock && cur[run.i0].type === next[run.j0].type
                && JSON.stringify(cur[run.i0].attrs) === JSON.stringify(next[run.j0].attrs)

            if (single) {
                // Same paragraph: replace only the changed inline range.
                const a = cur[run.i0].content
                const b = next[run.j0].content
                let start = a.findDiffStart(b)
                let endA = a.findDiffEnd(b)

                if (start === null || endA === null) {
                    continue
                }

                let ea = endA.a
                let eb = endA.b
                const overlap = start - Math.min(ea, eb)

                if (overlap > 0) {
                    ea += overlap
                    eb += overlap
                }

                const base = from + 1
                tr = tr.replace(base + start, base + ea, b.cut(start, eb).size ? new (tiptap().pmModel.Slice)(b.cut(start, eb), 0, 0) : tiptap().pmModel.Slice.empty)
                continue
            }

            const slice = run.j1 > run.j0
                ? new (tiptap().pmModel.Slice)(target.content.cut(nextPos[run.j0][0], nextPos[run.j1 - 1][1]), 0, 0)
                : tiptap().pmModel.Slice.empty

            tr = tr.replace(from, to, slice)
        }

        if (!tr.docChanged) {
            return false
        }

        if (remote) {
            tr = tr.setMeta(REMOTE, true).setMeta('addToHistory', false)
        }

        if (before) {
            before(tr.doc.toJSON())
        }

        editor.view.dispatch(tr)

        return true
    }

    /**
     * Where the caret would be in `target` for a selection captured on
     * `from`: unchanged before the first difference, shifted after the
     * last, clamped inside.
     */
    function mapSelection(from, target, selection) {
        const start = from.content.findDiffStart(target.content)

        if (start === null) {
            return selection
        }

        const end = from.content.findDiffEnd(target.content)
        const map = (pos) => {
            if (pos <= start) return pos
            if (end && pos >= end.a) return pos + (end.b - end.a)
            return Math.min(pos, end ? end.b : target.content.size)
        }

        return { anchor: map(selection.anchor), head: map(selection.head) }
    }

    /** Restore a selection (anchor/head positions) on the editor if it is focused. */
    function select(editor, selection) {
        if (!selection || !editor.isFocused) {
            return
        }

        const { TextSelection } = tiptap().pmState
        const size = editor.state.doc.content.size
        const clamp = (pos) => Math.max(0, Math.min(pos, size))

        try {
            editor.view.dispatch(editor.state.tr.setSelection(TextSelection.create(editor.state.doc, clamp(selection.anchor), clamp(selection.head))).setMeta(REMOTE, true))
        } catch (e) {
            // A position that no longer resolves: keep whatever the editor did.
        }
    }

    /** The node at a child-index path, with its position, or null. */
    function nodeAt(doc, path) {
        let node = doc
        let pos = 0

        for (const index of path || []) {
            if (index < 0 || index >= node.childCount) {
                return null
            }

            let offset = pos + (node === doc ? 0 : 1)
            for (let k = 0; k < index; k++) {
                offset += node.child(k).nodeSize
            }
            pos = offset
            node = node.child(index)
        }

        return node === doc ? null : { node, pos }
    }

    /** Position where a block would be inserted at a child-index path. */
    function insertionAt(doc, path) {
        const parentPath = (path || []).slice(0, -1)
        const index = (path || [])[(path || []).length - 1] ?? doc.childCount
        const parent = parentPath.length ? nodeAt(doc, parentPath) : { node: doc, pos: -1 }

        if (!parent) {
            return doc.content.size
        }

        let pos = parent.pos + 1
        for (let k = 0; k < Math.min(index, parent.node.childCount); k++) {
            pos += parent.node.child(k).nodeSize
        }

        return pos
    }

    /**
     * Put the other editor's discarded fragment back: replace ours where it
     * still is, otherwise insert theirs at the reported spot. A plain user
     * transaction, so it is saved like any edit.
     */
    function recover(editor, conflict) {
        const { Fragment } = tiptap().pmModel
        const doc = editor.state.doc
        const theirs = toNodes(editor, conflict.theirs)
        const ours = toNodes(editor, conflict.ours)
        let tr = editor.state.tr

        if (conflict.kind === 'block') {
            const at = nodeAt(doc, conflict.block)

            if (at && ours.length && ours.some((node) => node.eq(at.node))) {
                tr = tr.replaceWith(at.pos, at.pos + at.node.nodeSize, Fragment.from(theirs))
            } else if (theirs.length) {
                tr = tr.insert(insertionAt(doc, conflict.block), Fragment.from(theirs))
            }
        } else {
            const at = nodeAt(doc, conflict.block)
            const inline = Fragment.from(theirs.flatMap((node) => (node.isTextblock ? children(node) : [node])))
            const oursText = ours.map((node) => node.textContent).join('')

            if (at && at.node.isTextblock) {
                const text = at.node.textContent
                const found = oursText !== '' ? text.indexOf(oursText) : -1
                const from = found !== -1 ? at.pos + 1 + found : at.pos + 1 + text.length
                const to = found !== -1 ? from + oursText.length : from
                tr = tr.replaceWith(from, to, inline)
            } else if (inline.size) {
                tr = tr.insert(insertionAt(doc, conflict.block), editor.schema.nodes.paragraph.create(null, inline))
            }
        }

        if (!tr.docChanged) {
            return false
        }

        editor.view.dispatch(tr)

        return true
    }

    return {
        find,
        element,
        docs: { toDoc, toNodes, normalize, plainText, preview },
        merge: { blocks: mergeBlocks },
        apply: { toEditor, mapSelection, select, recover, REMOTE },
    }
})()
