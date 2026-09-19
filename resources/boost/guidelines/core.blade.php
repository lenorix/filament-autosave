# Lenorix Filament Autosave

Column-by-column autosave for Filament forms with one-step Undo, drafts, and a
status indicator; untouched fields pick up other editors' changes.

- Edit pages persist eligible fields to the record.
- Create/custom pages store drafts in Laravel Cache until explicit submit.
- Other editors' changes reach untouched fields after a save and by polling;
  listed plain-text fields are merged word by word and listed RichEditors
  block by block (structurally, over the Tiptap document), in the browser
  and on the server, and the other editor's discarded words or blocks can
  be recovered from the indicator. There is no WebSocket/SSE transport. Undo detects a
  concurrent change and reports a conflict instead of restoring over it.

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
`autosaveDataPath` property. The indicator is built only from Filament
components (`x-filament::badge`, `x-filament::link`, `x-filament::callout`)
and ships no CSS: Filament 4/5 compiles no generic Tailwind utilities, so
never add `text-*`/`flex`/`dark:` classes or a stylesheet to it; extra
content goes in a callout's heading/description/footer/controls slots.

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
AutosaveSkipped, AutosaveFailed, AutosaveUndone, AutosaveConflict, AutosaveSynced}` as objects
(`record` is `null` for drafts); hook monitoring to these, not to the Livewire
`autosave-status` event.

## Extension contract

Only trait members tagged `@api` are stable (list pinned by
`tests/Unit/ExtensionContractTest.php`): `shouldAutosave`, `autosaveDebounce`,
`autosaveExcept`, `autosavePollInterval`, `autosaveMergeFields`, `beforeAutosave`,
`getAutosaveValidationRules`, `afterAutosave`, `getUndoTtlMinutes`,
`resolveAutosaveForm`, `getAutosaveStatePath`, `persistAutosaveForm`,
`getAutosaveFormContext`, plus the public `autosave`, `flushAutosave`,
`syncAutosave`, `undoAutosave`, `restoreDraft`, `discardDraft`,
`clearAutosaveDraft`, `isAutosaveEnabled`, `getAutosaveDebounce`,
`getAutosavePollInterval`, `getAutosaveMergeFields`, `getAutosaveExcept`. Every other `protected` method is
internal: never tell a consumer to override it, and prefer the package events
for observation. Adding an `@api` tag is an API decision that must update the
test, README and CHANGELOG together.

## Explicit saves

`autosave()` is the background entry point: it never throws and reports
failures through the indicator. For explicit actions (a "Generate slug" button,
a "Publish" toggle, an "Apply template" modal) call `flushAutosave(): bool` instead. It runs
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
and `afterSave` hooks, dispatches `RecordUpdated` and `RecordSaved` (built only
when the host is a `Filament\Resources\Pages\Page` — an Edit page or a custom
resource page with `HasAutosaveForForm`; relation managers, actions and plain
components dispatch neither), and sends
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
(non-relationship) repeater is autosaved only when every row resolves its own
distinct collection (persist a `Hidden` uuid per row and use
`->collection(fn (Get $get) => 'row_'.$get('uuid'))`); a shared collection
stays blocked and reported as pending, and deleting a row never deletes its
collection (the host cleans orphans). Create drafts never store uploads/media.
Spatie files enter the upload ledger from a boot-time `created` listener on
the media model (`AutosaveMediaJournal`), before the file reaches disk; do not
re-add a post-save diff for this.

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
user is not editing without waiting for a save. Polling is off whenever the
post-save refresh is (`refresh_unchanged_fields` false or `dirty_only` false):
it relies on the same refill. Same eligibility rule as the
post-save refresh, shared in `HasAutosaveBase` (`autosaveRefreshablePaths`,
`refillAutosavePaths`): never relationships, uploads, excluded or dirty fields.
A dirty field that also changed remotely is reported as `stale` and left
alone. `poll_relationships` (default true; plugin `pollRelationships()`)
extends the same refill/stale rule to relationship repeaters, relation
selects, upload columns and Spatie media, via `autosavePolledRelationFields`,
`autosaveChangedRelationPaths` (one UNION detector query: count + max
updated_at per relation; a relation without timestamps is re-read every poll)
and `refillAutosaveRelationPaths`. It emits `status: synced` (+ `refreshed`, `stale`) and the
`AutosaveSynced` event only when something changed, never writes to the
database, never touches Undo. The controller pauses polling while a save is
pending/in flight or the tab is hidden and backs off after 3 failures (max
60 s). Idle cost: one query (`updated_at` only on timestamped models).

Merging (`merge_fields`, plugin `mergeFields()`, page `autosaveMergeFields()`;
top-level TextInput/Textarea/MarkdownEditor/RichEditor only, others ignored
with a warning) lives in `HasAutosaveMerge` + `AutosaveTextMerge` (word-level
diff3 and a diff-match-patch `apply()` port, no dependencies) +
`AutosaveRichMerge` (structural diff3 over the ProseMirror document via
`ueberdosis/tiptap-php`, a filament/forms dependency; block alignment by
`attrs.id` or content similarity, inline runs through the text tokenizer with
marks, image/customBlock/mention/mergeTag atomic; HTML and JSON columns are
two serialisations of the same document) + `AutosaveSync` (versioned
payloads, `v: 1`). The server keeps no base: `autosave(array $mergePatches)`
takes `path => patch text` (or `['base' => …, 'ours' => …]`) for plain text
and `path => ['base' => <document>]` for a RichEditor, plays it on the
column's current value and writes with a per-column compare-and-swap,
retrying with backoff (5→100 ms) up to `merge_retries` (default 10). Exhausted: column unwritten, user's text stays dirty, reported as
pending + `conflicts[path][].reason = 'contended'` with `merged` (merge against
the latest value) and `patches[path].theirs`; `AutosaveConflict` fires; status
is `validation`. Overlapping ranges: last save wins there only, `reason =
'overlap'`. `syncAutosave(array $mergeBaseHashes)` adds `patches` for stale
mergeable fields unless the browser's hash matches. Without a patch a field
stays last-write-wins.

RichEditor specifics: the form (and the browser) always holds the Tiptap
document, whatever the column stores, so `merged`, `patches.theirs` and the
values the browser sends are documents; conflict fragments (`ours`/`theirs`)
are lists of nodes, with `kind` (`inline`|`block`), `block` (child-index path
in the merged document) and `position` (code-point offset into the plain
text). A rich field is pre-merged in `autosavePreparePhase()`
(`premergeAutosaveRichFields()`: one SELECT of the rich columns, merge into
the form state) *before* the form dehydrates, because the RichEditor's own
`beforeStateDehydrated` cleanup deletes every attachment the submitted
document no longer references — merging first is what keeps an image the
other editor still uses (rule: files to delete = ids stored before minus ids
in the merged document). The conditional write then rebases on the value the
pre-merge read (that value is the base, the pre-merged document is ours) so
nothing is applied twice; only what landed in between is merged in. The
written value is always canonical (`AutosaveRichMerge::canonical()`); a
seeded non-canonical column is rewritten once and acknowledged. A text patch
string for a rich field is no patch. Undo stays disabled for a RichEditor
with an attachment provider, like any file operation.

Browser side: `resources/js/autosave-merge.js` (inlined by the indicator view
through Livewire's `@assets`, so it loads once into the head and never rides
in a component re-render; only when `getAutosaveMergeFields()` is non-empty;
no asset publishing) exposes `window.FilamentAutosaveMerge = { engine,
createSync, apply }`. `engine` mirrors the PHP tokenizer/diff/diff3/patch
text on code-point arrays (a browser test pins parity with `makePatch()`
and `merge()`); `createSync(fields)` holds the per-field base (mount, then
each acknowledged save/refill/merge) and hashes the server named, builds
`patches(values)` and `baseHashes()`, and `receive(payload, live, sent)`
turns a reply into input updates + conflicts; `apply.toInput(el, value,
snapshot)` maps the caret/selection through the diff (UTF-16 ⇄ code points),
fires `input` so the bound state follows, and `apply.recover(value,
conflict)` puts theirs back. The Alpine controller (`mergeSync`, `conflicts`,
`receiveMerge()`, `recoverConflict()`, `dismissConflicts()`) sets
`lastSyncedStateJson` before applying so the watchers do not read a merge as
a user edit, folds non-contended `merged` into the baseline, keeps a
contended field dirty and re-arms a save from the rebased value, and
resyncs bases after undo/restore. Invariant: a base only advances to a value
the input reflects.

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
