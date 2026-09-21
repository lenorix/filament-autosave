# Filament Autosave: coding guidance

This package adds autosave to Filament forms. Treat the package as a reusable
library: preserve Filament behavior, keep application integrations on the
documented surface, and do not make consumers depend on implementation
details.

## Host traits

Choose the trait that matches the Livewire host:

- `HasAutosave` for `EditRecord` pages.
- `HasAutosaveForCreate` for `CreateRecord` pages and custom draft forms.
- `HasAutosaveForRelationManager` for relation-manager create and edit modals.
- `HasAutosaveForForm` for action and modal forms, table forms, and standalone
  Livewire components.

`HasAutosave` and `HasAutosaveForCreate` resolve the page form automatically.
`HasAutosaveForRelationManager` resolves the mounted action form, scopes it to
the owner, relationship, action, and row, and manages its modal baseline.

Generic `HasAutosaveForForm` hosts must:

1. Call `mountHasAutosaveForForm()` from `mount()`.
2. Render `filament-autosave::autosave-indicator` with `mode: form`.
3. Return a stable, unique value from `getAutosaveFormContext()`.
4. Override `resolveAutosaveForm()` and `getAutosaveStatePath()` when the
   active schema is not the default form (for example, `mountedActions.*.data`).

Record-backed generic forms persist records and can Undo. Recordless generic
forms keep drafts in Laravel Cache; they do not create records or persist
uploads until the host explicitly submits them. Create actions must clear the
draft after creating the record.

Do not use `HasAutosaveUploads` as a consumer-facing trait. It is composed by
the public traits that need upload support.

## Public extension surface

Only use the following documented hooks and methods from application code:

- `shouldAutosave()`, `autosaveDebounce()`, and `autosaveExcept()` for page-level behavior.
- `autosavePollInterval()` and `autosaveMergeFields()` for page-level sync options.
- `beforeAutosave(array $data): array` to change eligible state before validation.
- `getAutosaveValidationRules(): array` for rules used by autosave.
- `afterAutosave(object $record): void` after a successful Edit-page save.
- `resolveAutosaveForm()` and `getAutosaveStatePath()` for generic form resolution.
- `persistAutosaveForm(array $data)` for a custom generic-form persistence lifecycle.
- `getAutosaveFormContext()` for draft and Undo scope.
- `getUndoTtlMinutes()` for the Undo snapshot lifetime.

The public calls are `autosave()`, `flushAutosave()`, `undoAutosave()`,
`syncAutosave()`, `restoreDraft()`, `discardDraft()`, and
`clearAutosaveDraft()`. `autosave()` reports failures through the indicator;
`flushAutosave()` is synchronous and propagates validation and persistence
exceptions.

Use typed events under `Lenorix\FilamentAutosave\Events` to observe saves,
skips, failures, synchronization, conflicts, and Undo. Do not override other
protected methods or mutate locked state, hashes, snapshots, or upload ledgers.

## Autosave cycle

The cycle is authorize → prepare → validate → persist → commit → report.

- Authorization uses the host page's normal authorization path.
- Preparation reads Filament's dehydrated state, relationship state, pending uploads, and merge inputs.
- Validation runs Filament field rules and autosave-specific rules.
- Persistence writes columns, relationships, media, and uploads that survived validation.
- Commit advances acknowledged hashes and snapshots only after the write commits.
- Reporting updates the indicator and dispatches package events.

Record-backed Edit and Create cycles run in one database transaction. Filament's
`beforeValidate`, `afterValidate`, `beforeSave`, and `afterSave` hooks run in
that cycle. `mutateFormDataBeforeSave()` is applied inside the transaction.
Notifications are sent only after a successful commit. A `Halt` follows
Filament's rollback decision: a rollback Halt discards the cycle; a committed
Halt keeps and acknowledges the write.

Generic forms use the host transaction when the host exposes one. Custom
`persistAutosaveForm()` implementations are responsible for their own
transaction and relationship lifecycle when no host transaction exists.

Mounted action and modal forms bridge Filament's form-validation callbacks,
data mutator, and `before`/`after` action callbacks into autosave. The action
submit callback is not run by background autosave. Its success notification and
redirect run only after an explicit `flushAutosave()` commits successfully.

## Fields, validation, and dirty-only writes

`dirty_only` defaults to `true`. Hashes are kept per top-level state path; a
successful cycle advances hashes only for values actually written. Never copy
or mutate these hashes manually.

Invalid fields are skipped and reported as pending; valid sibling fields may
still save. Blank required values, invalid uploads, and incomplete groups,
builders, or single-column repeaters leave the existing stored value intact.
Those containers are written only when all child values are valid.

Password fields, `dehydrated(false)` fields, fields in `except`, temporary
uploads, and undeclared client keys are excluded. Page authorization still
applies to autosave, polling, draft restoration, and Undo.

Relationship fields must keep stable top-level paths. Let Filament relationship
components run their normal `saveRelationships()` callbacks; do not duplicate
relationship writes in an application hook. Nested relationship repeaters are
resolved from the deepest changed row upward before the parent payload is
written.

## Refresh, polling, and merge behavior

`refresh_unchanged_fields` defaults to `true` and refreshes clean model-backed
columns in the save response. It never replaces a locally dirty field and does
not refresh relationships or uploads.

`poll_interval` defaults to 5000 ms and `0` disables polling. Polling requires
dirty-only writes and post-save refresh. It pulls untouched columns and, when
`poll_relationships` is true, untouched relationship, upload, and media state.
Relations without timestamps compare persisted row/pivot content hashes, adding
reads per relation. Nested relationship components are fingerprinted up to
`poll_relationship_depth` (3 by default); set it to 0 for direct relations
only. Timestamp-free relations hash at most `poll_relationship_max_rows` rows
(500 by default); larger collections use key/count detection, so timestamps are
recommended at scale.
A locally dirty field changed remotely is reported as `stale` and remains
untouched. Polling never writes to the database and never creates an Undo
snapshot. The browser pauses polling while a save is pending or the tab is
hidden.

`merge_fields` applies only to top-level `TextInput`, `Textarea`,
`MarkdownEditor`, and `RichEditor` fields. Non-overlapping plain text changes
are merged by word; RichEditor documents are merged by block. Overlapping
changes remain last-write-wins. A contended field remains dirty and is retried
on a later cycle. Do not treat a RichEditor patch as plain text.

The browser controller is loaded by the indicator through Livewire assets; no
asset publishing or Node runtime is required by consumers. Browser behavior is
covered by Pest's browser plugin, with Playwright used only as the driver.

## Undo

Undo is single-step. It restores saved columns, pivot data, and supported
relationship rows only when their expected values still match the database.
If another editor changed a value being restored, Undo reports a conflict and
does not overwrite it. Changes to unrelated columns do not block Undo.

`relationship_undo_depth` defaults to 8. Deeper graphs disable Undo for that
snapshot. Files, media, and other external storage are excluded unless a
reversible `AutosaveExternalUndoAdapter` is registered; an unsupported
external field must never be presented as safely undoable.

## Uploads and media

Native `FileUpload` fields support add, remove, reorder, and size/MIME
validation. Spatie Media Library fields require
`filament/spatie-laravel-media-library-plugin` and support the same operations.
Relationship-row media is attached after the child row exists; a row rejected
by validation stores no media.

Each JSON repeater row must use a distinct media collection, normally based on
a persisted UUID. A shared collection is blocked to prevent one row from
deleting or overwriting another row's media.

Uploads are staged before persistence and cleaned up on normal failures. The
upload ledger and scheduled `filament-autosave:prune-uploads` command cover
files left by interrupted requests. Files cannot be rolled back by a database
transaction, so uploads do not receive Undo by default.

## Verification rules

For behavior changes, run:

~~~bash
composer test
vendor/bin/pint --test
~~~

Run `composer test:browser` when changing the indicator, debounce, polling,
merge, stale-field, draft-restore, or upload browser behavior. Add integration
coverage for both Edit pages and generic forms when changing shared base logic.

Concurrency changes should cover two Livewire instances, different columns,
the same column, remote changes before Undo, validation skips, and nested
relationship or upload state where relevant.
