# Changelog

## Unreleased

- Added `HasAutosaveForRelationManager`: a relation manager autosaves its
  edit and create modals with nothing to wire up beyond the `use` statement.
  It is `HasAutosaveForForm` with the mounted action's schema and state path,
  a scope built from owner + relationship + action + row (so two rows, or two
  relation managers on the same owner, never share a draft or an Undo
  snapshot), and its own indicator injected into the action's modal
  automatically. A create action still needs one line, the same one every
  `HasAutosaveForCreate`/`HasAutosaveForForm` consumer already needs:
  `->after(fn ($livewire) => $livewire->clearAutosaveDraft())`.
- The browser flushes an edit still waiting on its debounce when the tab is
  hidden or the page is being left (`beforeunload`, request sent with
  `keepalive`), so closing the tab a second after typing no longer drops the
  last change. Best effort on unload: a page torn down instantly or a form
  over 64 KB may still lose it.
- A save asked for while a poll is in flight waits for the poll and is
  replayed by it. Before, the poll's reply was half applied — its `synced`
  status dropped, its refill read as a user edit — and the badge fell back
  to "unsaved" in the middle of the save.
- A save request that resolves without a status no longer leaves the badge
  on "saving" for good (which also swallowed every later save); the
  controller warns in the console and marks the form unsaved.
- A failed or skipped cycle rolls back only the Spatie media it created. The
  baseline was captured at page load and carried across requests, so any
  no-write cycle (validation skip, Halt, a failing hook) deleted every media
  row and file added to the record since — another editor's upload included.
- A `Halt` raised after the record write with the transaction kept (Filament's
  default) now keeps the files just stored and acknowledges the write, on Edit
  pages and generic forms; the column no longer names a deleted file and the
  next cycle does not rewrite it.
- Record-backed generic forms save only the relationships the cycle touched
  instead of the whole schema, so an untouched repeater is never rewritten
  from the tab's stale copy over another editor's change.
- Dirtiness is judged on the value the form shows, never on what
  `mutateFormDataBeforeSave()` turned it into: a slugifying mutator left the
  field dirty forever (rewritten every cycle, never refreshed from others).
- Polling is off whenever `refresh_unchanged_fields` or `dirty_only` is
  off, and a remote change is no longer forgotten when refilling it fails.
- The merge retry re-reads a contended column with a locking read, so under
  MySQL `REPEATABLE READ` it sees the committed value instead of the
  transaction's first snapshot and no longer always ends contended.
- Text merge: invalid UTF-8 in a value or patch is rejected (it used to read
  as "deleted everything" and overwrite the column) and the field falls back to
  last-write-wins with a warning; the engine can no longer take the cycle down.
  A fuzzy-matched replacement inserts where the deletion was, not into the
  synthetic padding.
- The error status is scoped to the component like every other status, so a
  nested component's indicator no longer sticks at "saving"; a page whose
  `shouldAutosave()` is false answers with `idle` instead of silence.
- Stripping a pending file from a multi-file upload keeps the list a list
  (a JSON column received `{"1": …}`).
- The upload-ledger index outlives its entries, so a lone interrupted upload
  is pruned; Spatie ledger tokens are rolled back, not forgotten, on failure.
- `AutosavePlugin` falls back to the shipped config defaults (750 / 72 / 90)
  instead of its own 1500 / 24 / 30 when a key is missing.
- Merge concurrent edits to `RichEditor` content structurally: list a
  top-level `RichEditor` in `merge_fields` (or `mergeFields()` /
  `autosaveMergeFields()`) and `AutosaveRichMerge` combines both editors'
  documents as trees over `ueberdosis/tiptap-php` (a `filament/forms`
  dependency): blocks matched by id or content, words and marks merged
  inside a shared paragraph, images / custom blocks / mentions / merge tags
  atomic, the same per-column compare-and-swap and `merge_retries` as plain
  text, HTML and JSON columns alike (the written value is always the
  editor's canonical form). The browser sends `autosave(['body' => ['base'
  => <document>]])`; `merged`, `patches.theirs` and conflict fragments are
  documents / node lists, and conflicts carry `kind`, `block` and `position`.
  A rich field is merged before the form dehydrates, so the editor's
  attachment cleanup keeps every image the merged document still references.
  A rich merge adds exactly one query (the read of the rich columns).
- The browser applies a merged `RichEditor` document inside the live TipTap
  editor (`resources/js/autosave-rich-merge.js`, loaded with the text merge
  script and borrowing ProseMirror from Filament's own editor bundle): only
  the blocks that differ are replaced, in one transaction, so the caret,
  the selection and text typed while the save was in flight are kept; a
  clean field refilled by a poll goes the same way instead of a reset.
  Rich conflicts are previewed as text in the callout and recovered by
  putting the other editor's nodes back at their block; a contended field
  adopts the latest merge and stays dirty.
- Generic forms (`HasAutosaveForForm`) store a `RichEditor`'s column format
  (HTML or JSON, through its state cast) instead of the raw Tiptap document
  the form holds.
- Refills (post-save refresh, polling, merged fields) carry array attributes
  whole: `Schema::fillPartially()` flattens the state with dot notation and
  never matched a JSON column or a `RichEditor` document, so those fields were
  silently left as they were.
- Merge concurrent edits to plain-text fields instead of last-write-wins:
  list them in `merge_fields`, `AutosavePlugin::mergeFields()` or a page's
  `autosaveMergeFields()` (top-level `TextInput`, `Textarea`, `MarkdownEditor`;
  others are ignored with a warning). `autosave(array $mergePatches)` accepts
  a diff-match-patch patch (or the base) per field and plays it on the
  column's current value; the result is written with a per-column
  compare-and-swap retried with backoff up to `merge_retries` (default 10). A
  field still contended is left unwritten and dirty, reported as pending with
  `reason: contended`, and `AutosaveConflict` fires. `syncAutosave(array
  $mergeBaseHashes)` returns the other editor's value for stale mergeable
  fields. The `autosave-status` payload is versioned (`v: 1`) and gains
  `merged`, `conflicts`, `patches`; `AutosaveSaved`, `AutosaveSynced` and
  `AutosaveConflict` gain matching properties (new optional constructor
  parameters).
- The browser does its part of the merge, with no build step and no
  dependency: when a component lists merge fields, the indicator loads a
  script of its own once per page through Livewire's `@assets`
  (`resources/js/autosave-merge.js`, ~24 KB, the same word-level diff, diff3
  and patch format as the server; never part of a Livewire response) and the controller keeps the
  last acknowledged value of each field as its base, sends a patch per dirty
  field with `autosave()` and the hashes it holds with `syncAutosave()`, and
  merges `merged` / `patches.theirs` into the input while keeping the caret
  and selection in place (also for text typed while the request ran). Words
  of another editor a save replaced, or a field nobody could write, are
  listed in a callout with a "recover the other version" link; a contended
  field adopts the merge against the latest value, rebases and is retried by
  the next cycle. Translations gain `conflicts`, `contended`, `recover`,
  `dismiss`. Fields not listed, and pages without merge fields, are unchanged.
- Autosave `SpatieMediaLibraryFileUpload` fields inside a JSON (non-relationship)
  `Repeater` when every row resolves its own distinct collection (a persisted
  `Hidden` uuid plus `->collection(fn (Get $get) => 'row_'.$get('uuid'))`).
  Each row's media is written to its own collection, rows can be reordered
  without moving media, and a failure after the write removes only the affected
  row's new files. Rows sharing one collection stay blocked and are reported as
  pending. Deleting a row does not delete its collection; the host cleans orphans.
- Spatie Media Library files are journaled in the upload ledger the moment
  their `media` row is created (a boot-time `created` listener on the media
  model), before the file reaches disk. This closes the residual window where a
  process killed between Spatie's file write and the journal entry left an
  untracked file. `AutosaveUploadLedger::append()` extends an existing entry.
- Refactor: one-step Undo storage and its optimistic conflict checks now live in
  a single `AutosaveUndo` engine shared by Edit pages and generic forms, and
  both use the same per-field hash. Generic forms (`HasAutosaveForForm`)
  previously hashed with sha256; a tab opened before the deploy still carries
  those hashes, which are recognised and re-baselined on its next request
  without marking any field dirty, so nothing is written with stale values.
  Cached Undo targets keep working. No other behaviour change.
- Restructure the README into a guided walkthrough with a table of contents;
  no facts removed, internal details folded into collapsible blocks.
- The indicator is now built only from Filament components: skipped and stale
  fields render as badges and validation messages inside a `callout`, so the
  panel's light and dark themes apply everywhere. The package stylesheet and
  its Filament asset registration are gone (nothing to publish).
- Fix pending relationship rows and edits being lost when a page's
  `handleRecordUpdate()` refills the form without saving relationships
  (`$this->form->fill($this->form->getState(false))`, as lara-zeus/spatie-translatable
  1.x does): the refill re-hydrated every relationship repeater from the
  database before the package's own pass wrote them. The resolved state is now
  restored for relationships the hook left untouched, while hooks that did save
  keep their re-keyed state so rows are never created twice.
- Fix a fatal error on Filament 4.0.x, which has no `Filament\Resources\Events`:
  `RecordUpdated`/`RecordSaved` are now dispatched only when the classes exist.
- Generic forms fold every upload field's hash into `autosaveObservedHash`,
  as Edit pages already did, so the browser watcher notices an upload-only
  server-side change; the watcher itself now also runs in `form` mode.
- Ship a Spanish (`es`) translation; a test keeps every shipped locale in
  key parity with English.
- The `refreshed` payload (post-save refresh and `syncAutosave()`) now lists
  only declared form fields: a generic form filled with `attributesToArray()`
  previously reported and refilled every model column (`id`, `category_id`, …).
- Fix `HasAutosaveForForm` components nested in a page never receiving their
  own `autosave-status` event: a plain Livewire dispatch from a nested
  component only reached global listeners, so the indicator stuck at "saving"
  and its guard dropped every later save. The event is now dispatched to the
  component itself.
- Fix the Undo link being rendered only in `edit` mode: record-backed generic
  forms had `autosaveCanUndo` and `undoAutosave()` but no button. It is now
  rendered in every mode and hidden while Undo is unavailable (drafts).
- Add polling for other editors' changes. With `poll_interval` (default 5000
  ms, `0` disables; plugin `pollInterval()`, page `autosavePollInterval()`) the
  browser calls the new public `syncAutosave()` on a timer: clean, model-backed
  columns another editor changed are refilled using the same eligibility rule
  as the post-save refresh, fields that are dirty locally and changed remotely
  are reported as `stale` and left untouched, and a new `synced` status carries
  `refreshed` and `stale` to the indicator. `AutosaveSynced` (`page`, `record`,
  `refreshed`, `stale`) is dispatched alongside. Polling pauses while a save is
  pending or in flight and while the tab is hidden, and backs off after three
  consecutive failures. An idle poll costs one query. The post-save refresh on
  Edit pages and generic forms now shares this code path in `HasAutosaveBase`.
- Fix the indicator's helper text (pending fields, validation messages, stale
  fields) rendering unstyled and without a dark variant: it relied on Tailwind
  utilities that Filament 4/5 does not ship in its compiled theme. The
  indicator now uses `fi-autosave-*` classes backed by a small stylesheet
  registered as a Filament asset (published by `filament:assets`), using
  Filament's colour tokens in light and dark mode.
- `refresh_unchanged_fields` now also applies to record-backed generic forms
  (`HasAutosaveForForm`): after a successful save, clean model-backed columns
  are re-read from the record and reported in the status event's `refreshed`
  payload, so another editor's changes to untouched fields show up in the same
  response. Dirty, relationship, upload, and excluded fields are never touched;
  drafts are unaffected.
- Declare the stable extension surface: the trait members consumers may
  override or call are tagged `@api` and pinned by a test; every other member
  is internal and may change in a minor release. No behaviour change.
- Fix Undo snapshots being shared between live instances of the same page or
  form for one user on one record (two tabs): the second tab's autosave
  overwrote the first tab's snapshot, so its Undo restored the other tab's
  column. Undo cache keys now carry the Livewire component id.
- Add package events (`AutosaveSaved`, `AutosaveSkipped`, `AutosaveFailed`,
  `AutosaveUndone`, `AutosaveConflict`) dispatched as objects from every trait,
  so hosts can monitor autosave outcomes; failures previously only reached a
  `Log::warning` with the exception class.
- **Behaviour change:** every autosave write now runs inside a database
  transaction. Filament's `beginDatabaseTransaction()` is a no-op unless the
  panel opts in with `Panel::databaseTransactions()` (off by default), so a hook
  failing after `handleRecordUpdate()` used to leave the columns written and the
  relationship rows not. When the panel owns transactions its methods are still
  used; otherwise the package opens its own, honouring `Halt`'s rollback flag.
  Recordless drafts are unaffected.
- Fix newly added relationship rows being created twice when the page's
  `handleRecordUpdate()` already runs Filament's own save path (any hook calling
  `$this->form->getState()`, such as translatable Edit-page concerns). That path
  persists relationships, rebuilds repeater child schemas and re-keys created
  rows to `record-{id}`, but the components captured earlier kept a stale
  existing-record cache and re-created the same rows on the innermost-first
  pass. Pending relationship components are now re-read from the live form and
  their record caches cleared right before they are saved.
- Fix nested relationship repeaters (a relationship `Repeater` inside another
  relationship `Repeater`, at any depth) losing their changes on autosave while
  still reporting "saved". The parent's subtree was removed from the payload
  before the nested repeaters were resolved, so they were dropped, and Filament's
  `Repeater::saveToRelationship()` does not recurse into existing rows.
  Pending relationships are now resolved before any path is forgotten, saved
  innermost first (as Filament's own `Schema::saveRelationships()` does), and
  a relationship that still cannot be resolved is reported as a pending field.
- Keep per-field hashes across Livewire requests for `dirty_only`, without storing
  original form values. Reset after successful explicit saves and Undo; retain
  pending fields when validation or persistence skips them.
- Detect server-side form changes and defer autosave during active uploads.
- Autosave validated column-backed file uploads and top-level Spatie Media Library
  additions, removals and ordering. Only changed upload fields are processed.
- Autosave `FileUpload` and `SpatieMediaLibraryFileUpload` fields inside
  relationship `Repeater` rows together with the relationship write, on Edit
  pages and record-backed generic forms; a row failing validation skips the
  whole relationship and stores no file.
- Add `flushAutosave()`: a synchronous autosave cycle for explicit actions that
  throws a `ValidationException` instead of writing partially and propagates
  hook and persistence exceptions to the caller.
- Fix `RichEditor` content being dropped from the column write: the field was
  treated as a relationship, so a plain editor (no attachment provider) lost its
  text and never offered Undo. Content now stays in the column payload and the
  attachment callback runs in addition.
- Disable column-only Undo for autosaves involving file operations.
- Run Filament Edit lifecycle hooks, events, and saved notifications for
  autosaves.
- Save nested relationship components and restore database relationships in
  Undo; keep file-backed changes excluded from Undo.
- Show skipped validation fields in the autosave indicator.
- Add database, filesystem and controller regression tests.

## 0.1.0 - 2026-09-13

- Added autosave for Edit pages. After a short pause, changed form values are
  written to the record. Users can undo the latest write with one click.
- Added drafts for Create and custom pages. Users can restore or discard values
  that they have not submitted yet. Drafts are removed after a successful create,
  including “Create and create another”.
- Added a status indicator for unsaved, saving, saved, error, draft available,
  restored, and undone states.
- Added support for Filament 4 and 5, Livewire 3 and 4, Laravel 12 and 13, and
  PHP 8.4 and 8.5.
- Added config, plugin, and page settings for debounce time, excluded fields,
  draft and undo lifetime, indicator position, timestamp visibility, and the
  enabled state.
- Added locked Livewire properties so a page that disables autosave cannot be
  enabled again by a browser request.
- Excluded password fields, pending uploads, excluded fields, and undeclared
  client data from autosave and drafts.
- Added option checks for `Select`, `CheckboxList`, and `ToggleButtons`, including
  values limited by the current tenant or team.
- Added lifecycle hooks and optional validation rules, including nested paths.
- Added database transactions for record updates and undo operations.
- Added tenant, guard, and user scoping for draft and undo cache keys.
- Added support for nested groups, repeaters, dark mode, and translations.
- Added unit and integration coverage for real Filament Edit and Create pages.
