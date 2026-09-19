# Changelog

## Unreleased

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
