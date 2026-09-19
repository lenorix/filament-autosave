# Lenorix Filament Autosave

Debounced autosave and a Filament status indicator.

- Edit pages persist eligible fields to the record.
- Create/custom pages store drafts in Laravel Cache until explicit submit.
- There is no polling, continuous remote-change sync, or merge UI during save.
  Undo detects a concurrent change and reports a conflict instead of restoring
  over it.

## Install

~~~bash
composer require lenorix/filament-autosave
~~~

Register the plugin in the panel:

~~~php
use Filament\Panel;
use Lenorix\FilamentAutosave\AutosavePlugin;

public function panel(Panel $panel): Panel
{
    return $panel->plugin(AutosavePlugin::make());
}
~~~

## Page traits

Use `HasAutosave` on `EditRecord` pages:

~~~php
use Lenorix\FilamentAutosave\HasAutosave;

class EditArticle extends EditRecord
{
    use HasAutosave;
}
~~~

Use `HasAutosaveForCreate` on `CreateRecord` or custom pages. Custom pages
must keep `public ?array $data = [];` and call `clearAutosaveDraft()` after
explicit persistence:

~~~php
use Lenorix\FilamentAutosave\HasAutosaveForCreate;

class CreateArticle extends CreateRecord
{
    use HasAutosaveForCreate;
}
~~~

Do not use `HasAutosaveUploads` directly; `HasAutosave` composes it for edits.
The indicator reads the form schema state path from the locked
`autosaveDataPath` property. Its CSS (`resources/css/autosave.css`) is a
FilamentAsset published by `filament:assets`; the indicator uses only
`fi-*`/`fi-autosave-*` classes, never raw Tailwind utilities, because
Filament 4/5 ships none in its compiled theme.

## Configuration

Precedence is config → plugin → page method; `except` lists are combined.

~~~php
AutosavePlugin::make()
    ->debounce(2000)                 // milliseconds
    ->except(['internal_notes'])
    ->exceptPages([EditPayment::class])
    ->showTimestamp(false)
    ->indicatorPosition('after')
    ->cacheTtl(48)                   // draft hours
    ->undoCacheTtl(45);              // undo minutes
~~~

Page methods: `shouldAutosave(): bool`, `autosaveDebounce(): ?int`, and
`autosaveExcept(): array`. Never redeclare public properties supplied by the
traits.

Hooks:

- Filament lifecycle hooks: `beforeValidate`, `afterValidate`, `beforeSave`,
  and `afterSave` (edit autosave)
- `beforeAutosave(array $data): array`
- `getAutosaveValidationRules(): array` (failed fields are skipped)
- `afterAutosave(object $record): void` (edit pages)
- `clearAutosaveDraft()` (create/custom pages)

## Events

All traits dispatch `Lenorix\FilamentAutosave\Events\{AutosaveSaved,
AutosaveSkipped, AutosaveFailed, AutosaveUndone, AutosaveConflict}` as objects
(`record` is `null` for drafts); hook monitoring to these, not to the Livewire
`autosave-status` event.

## Extension contract

Only trait members tagged `@api` are stable (list pinned by
`tests/Unit/ExtensionContractTest.php`): `shouldAutosave`, `autosaveDebounce`,
`autosaveExcept`, `autosavePollInterval`, `beforeAutosave`,
`getAutosaveValidationRules`, `afterAutosave`, `getUndoTtlMinutes`,
`resolveAutosaveForm`, `getAutosaveStatePath`, `persistAutosaveForm`,
`getAutosaveFormContext`, plus the public `autosave`, `flushAutosave`,
`syncAutosave`, `undoAutosave`, `restoreDraft`, `discardDraft`,
`clearAutosaveDraft`, `isAutosaveEnabled`, `getAutosaveDebounce`,
`getAutosavePollInterval`, `getAutosaveExcept`. Every other `protected` method is
internal: never tell a consumer to override it, and prefer the package events
for observation. Adding an `@api` tag is an API decision that must update the
test, README and CHANGELOG together.

## Explicit saves

`autosave()` is the background entry point: it never throws and reports
failures through the indicator. For explicit actions (a "Round prices" button,
an "Add from catalogue" modal) call `flushAutosave(): bool` instead. It runs
the same dirty-only cycle synchronously, throws `ValidationException` keyed by
state path (`data.title`) before writing anything, propagates exceptions from
`beforeAutosave()`, custom rules, hooks and persistence, lets Filament's
`Halt` through, and returns whether anything was written. Calling it from
inside a running cycle (for example from `afterAutosave()`) throws
`LogicException`. Available on all three traits.

Declared Filament field rules, including length and numeric limits, are applied
per field; a failing field is skipped while unrelated fields can still save.
The indicator lists skipped fields and their validation messages, including the
pending field paths returned by the validation cycle. Pending fields are not
validation-only: a blank required value, an invalid upload, or an incomplete
group/repeater/builder container also marks its top-level path pending, with
no per-nested-field message since the package cannot tell which nested value
caused the container to be dropped.
`beforeAutosave()` sees the complete eligible state. Pending Spatie media fields
are removed before `mutateFormDataBeforeSave()`; dirty-only filtering happens
just before the column write.
Edit autosave calls Filament's `beforeValidate`, `afterValidate`, `beforeSave`,
and `afterSave` hooks, dispatches `RecordUpdated` and `RecordSaved`, and sends
the standard saved notification after commit. `mutateFormDataBeforeSave()` runs
inside the transaction.

## Data and uploads

Edit autosave uses Filament's dehydrated state and runs
`beforeStateDehydrated()` callbacks. Fields with `saveRelationships()` callbacks
(including multi-select relationships, Repeater relationships, nested
containers, and RichEditor attachments) are saved when their raw state changes.
Relationship repeaters nested inside other relationship repeaters are resolved
before any parent path is removed from the payload and saved innermost first,
so a change deep in an existing row is written by that row's own repeater
(Filament's `Repeater::saveToRelationship()` only recurses when it creates a
row). A pending relationship that cannot be resolved is listed as pending
rather than dropped.
A `RichEditor` is always written as a column; its `saveRelationships()`
callback only manages file attachments and runs in addition, so a plain editor
without an attachment provider keeps its content and Undo.
Relation managers, action/modal forms, table forms, and standalone Livewire
components are separate components. Use `HasAutosaveForForm` with a
context-specific draft key and include the indicator in their views.
Column-backed `FileUpload` fields support add/remove/reorder. Top-level
`SpatieMediaLibraryFileUpload` fields support add/remove/reorder when the
Filament Spatie plugin is installed. Both kinds are also persisted inside a
`Repeater->relationship()` row, on Edit pages and record-backed generic forms:
media in an existing row attaches to the row's record, media in a new row is
attached by the repeater once it creates the row, and a row failing validation
skips the whole relationship without storing any file. Media inside a JSON
(non-relationship) repeater is not autosaved because rows would share one
collection. Create drafts never store uploads/media.

Password fields, `except` fields, temporary uploads, and undeclared client keys
are excluded. Nested groups/repeaters are one top-level value; an incomplete
or unsafe child skips the whole container. Upload saves have no Undo because
filesystem changes cannot be rolled back.

## Dirty-only and concurrency

`config/filament-autosave.php` enables `'dirty_only' => true` by default.
Edit pages keep a hash per top-level field, not a copy of the original values.
Only fields changed since the last successful baseline are written; hashes
advance only for fields actually written. Explicit saves and Undo reset them.
Different columns edited by two page instances are preserved; the same column
is last-write-wins. With `refresh_unchanged_fields` enabled, a successful save
also refreshes clean top-level model-backed fields from the record in the same
response; local dirty fields, relationships, and uploads are retained. The same
applies to record-backed `HasAutosaveForForm` components (the trait fills the
schema partially itself; drafts are never refreshed). Set `dirty_only` to
`false` only when the full eligible payload is required.

Polling (`poll_interval`, default 5000 ms, `0` off; plugin `pollInterval()`;
page `autosavePollInterval()`) makes the browser call the public
`syncAutosave()` on a timer so other editors' writes reach the fields this
user is not editing without waiting for a save. Same eligibility rule as the
post-save refresh, shared in `HasAutosaveBase` (`autosaveRefreshablePaths`,
`refillAutosavePaths`): never relationships, uploads, excluded or dirty fields.
A dirty field that also changed remotely is reported as `stale` and left
alone. It emits `status: synced` (+ `refreshed`, `stale`) and the
`AutosaveSynced` event only when something changed, never writes to the
database, never touches Undo. The controller pauses polling while a save is
pending/in flight or the tab is hidden and backs off after 3 failures (max
60 s). Idle cost: one query (`updated_at` only on timestamped models).

When changing this behavior, test two edit instances changing different columns
with `dirty_only` enabled, plus upload add/remove/reorder cases, including
uploads nested in relationship repeater rows.

## Verify

~~~bash
composer test
~~~

Tests are Pest Unit/Integration tests; do not add a Node test runner.
`tests/Browser` uses Pest's browser plugin (`composer test:browser`, excluded
from `composer test`); Playwright needs Node only as a browser driver, it is
not a test runner. Its assertions do not poll, so anything that appears after
the debounce is awaited with a bounded `script()` poll, never a fixed sleep.
