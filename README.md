# Filament Autosave

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lenorix/filament-autosave.svg?style=flat-square)](https://packagist.org/packages/lenorix/filament-autosave)
[![Total Downloads](https://img.shields.io/packagist/dt/lenorix/filament-autosave.svg?style=flat-square)](https://packagist.org/packages/lenorix/filament-autosave)
[![Tests](https://img.shields.io/github/actions/workflow/status/lenorix/filament-autosave/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/lenorix/filament-autosave/actions/workflows/tests.yml)
[![License](https://img.shields.io/badge/license-Unlicense-blue.svg?style=flat-square)](LICENSE.md)

Filament Autosave gives your forms a safety net: it saves changes after a short
pause, keeps unfinished forms as drafts, and lets users undo their latest edit.

- **Edit pages:** eligible changes are saved to the database automatically.
- **Create pages:** unfinished values are kept in Laravel Cache until the form
  is submitted.
- **Any form:** relation managers, actions, modals, table forms, and standalone
  Livewire components can opt in with `HasAutosaveForForm`.

## Requirements

- PHP 8.4 or 8.5
- Laravel 12 or 13
- Filament 4 or 5
- Livewire 3 with Filament 4, or Livewire 4 with Filament 5

The test suite covers both PHP versions, both Filament versions, and both
Laravel versions.

## Installation

```bash
composer require lenorix/filament-autosave
```

Register the plugin in your panel provider:

```php
use Lenorix\FilamentAutosave\AutosavePlugin;

public function panel(Panel $panel): Panel
{
    return $panel->plugin(AutosavePlugin::make());
}
```

The status indicator is included automatically and uses Filament's built-in
components. Its small stylesheet is registered as a Filament asset, so it is
published together with Filament's own assets by `php artisan filament:assets`
and follows the panel's light and dark themes. No Tailwind build step is
required.

Publish configuration, translations, or views only when you need to customise
them:

```bash
php artisan vendor:publish --tag="filament-autosave-config"
php artisan vendor:publish --tag="filament-autosave-translations"
php artisan vendor:publish --tag="filament-autosave-views"
```

## Quick start

### Edit pages

Add `HasAutosave` to an Edit page:

```php
use Filament\Resources\Pages\EditRecord;
use Lenorix\FilamentAutosave\HasAutosave;

class EditArticle extends EditRecord
{
    use HasAutosave;

    protected static string $resource = ArticleResource::class;
}
```

The browser watches the form's state path automatically. After 1.5 seconds
without changes, the page saves the eligible state and updates the indicator.
After a successful save, users can undo it for a short time.

The locked `autosaveDataPath` property contains the resolved state path when it
is useful to integrate with custom frontend code.

### Create pages

Add `HasAutosaveForCreate` to keep an unfinished form as a draft:

```php
use Filament\Resources\Pages\CreateRecord;
use Lenorix\FilamentAutosave\HasAutosaveForCreate;

class CreateArticle extends CreateRecord
{
    use HasAutosaveForCreate;

    protected static string $resource = ArticleResource::class;
}
```

Drafts can be restored or discarded when the user returns. They are removed
after a successful create, including “Create and create another”, and remain
available when validation fails.

The same trait works on custom pages that use the default `data` state path:

```php
use Filament\Pages\Page;
use Lenorix\FilamentAutosave\HasAutosaveForCreate;

class UserPreferences extends Page
{
    use HasAutosaveForCreate;

    public ?array $data = [];

    public function save(): void
    {
        // Persist the form state here.
        $this->clearAutosaveDraft();
    }
}
```

Create and custom-page drafts do not create records, store permanent uploads,
or attach Spatie media before the user explicitly submits the form.

## What gets saved?

Autosave works with Filament's dehydrated form state. Dehydration transforms are
applied, while fields marked `dehydrated(false)` are left out.

| Field | Autosaved? |
| --- | --- |
| Regular field backed by a database column | Yes |
| `Repeater` or `CheckboxList` stored in one column | Yes |
| Relationship field with a top-level `saveRelationships()` callback | Yes, when changed |
| `RichEditor`, with or without a file attachment provider | Yes, as a column; attachments cleaned up via its callback |
| `FileUpload` backed by a column, including nested fields | Yes, after upload validation |
| Top-level `SpatieMediaLibraryFileUpload` | Yes, changed collections only |
| `FileUpload` or `SpatieMediaLibraryFileUpload` inside a relationship `Repeater` row | Yes, with the row's relationship write |
| `SpatieMediaLibraryFileUpload` inside a JSON (non-relationship) repeater | No |
| Relationships inside groups, repeaters, and builders | Yes, when the relationship changes |
| Relationship `Repeater` nested inside another relationship `Repeater` (any depth) | Yes; each nested repeater saves its own rows, innermost first |
| Other `dehydrated(false)` fields | No |

Groups, sections, repeaters, and builders stored in one column are treated as a
single value. The container is written only when all of its children pass the
safety checks; if one child is invalid or incomplete, the existing column is
left untouched. This also prevents a container containing a password field from
being saved, while unrelated fields can continue to autosave.

## Validation and hooks

Autosave uses Filament's form state and validation pipeline, then applies the
package's additional safety checks and any rules returned by
`getAutosaveValidationRules()`:

- blank required values are skipped, so `NOT NULL` columns are not overwritten;
- `Select`, `CheckboxList`, and `ToggleButtons` values must be allowed values,
  including tenant- or team-specific options;
- invalid fields are skipped so valid, unrelated fields can still be saved;
- nested rules such as `items.*.qty` discard the affected top-level container.

```php
protected function beforeAutosave(array $data): array
{
    return $data;
}

protected function getAutosaveValidationRules(): array
{
    return ['slug' => ['required', 'string', 'max:120']];
}

protected function afterAutosave(object $record): void
{
    Cache::forget("user-{$record->id}");
}
```

`beforeAutosave()` runs with the complete eligible state, so cross-field rules
can inspect unchanged siblings. `mutateFormDataBeforeSave()` receives the
column and relationship state after pending Spatie media fields have been
removed. `dirty_only` is applied immediately before the column write.

On Edit pages, a successful autosave runs Filament's save lifecycle, including
`beforeValidate`, `afterValidate`, `beforeSave`, and `afterSave`, dispatches
`RecordUpdated` and `RecordSaved`, and sends the standard saved notification.
Use `afterAutosave()` for package-specific work that should run after each
autosave.

### Explicit saves with `flushAutosave()`

`autosave()` is designed for the background loop: it reports failures through
the indicator and never throws. Explicit actions — a "Round prices" button, an
"Add from catalogue" modal — usually need the opposite: the same dirty-only
write, refresh, and Undo behaviour, but with errors reaching the caller.

```php
Action::make('roundPrices')
    ->action(function (): void {
        $this->data['price'] = round($this->data['price']);

        $written = $this->flushAutosave();
    });
```

`flushAutosave()` runs one cycle synchronously and returns whether anything was
written. Validation errors abort the cycle before any write and are thrown as a
`ValidationException` keyed by state path (`data.title`), so Filament shows
them inline. Exceptions thrown by `beforeAutosave()`, custom rules, hooks, or
persistence propagate unchanged, and Filament's `Halt` propagates so the
surrounding action can stop cleanly. It is available on every autosave trait.

### Events

Every trait dispatches plain Laravel events so the host can observe autosave
without touching the indicator: `Lenorix\FilamentAutosave\Events\AutosaveSaved`
(`page`, `record`, `data`, `pending`), `AutosaveSkipped` (`reason` is
`validation` or `unchanged`, plus `pending` and `errors`), `AutosaveFailed`
(`exception`, `context` of `save`, `sync`, `undo` or `restore`), `AutosaveUndone`
and `AutosaveConflict` (`page`, `record`), and `AutosaveSynced` (`page`,
`record`, `refreshed`, `stale`) when a poll pulled another editor's changes.
`record` is `null` for drafts and Create pages. They are dispatched as objects, so type-hinted listeners work:

```php
Event::listen(AutosaveFailed::class, function (AutosaveFailed $event): void {
    report($event->exception);
});
```

## Extension points and stability

Only the members tagged `@api` in the traits are stable extension points; a
test (`tests/Unit/ExtensionContractTest.php`) pins that list, so it changes
only with a deliberate, documented decision. Everything else in the traits is
internal and may be renamed or reshaped in a minor release, even when it is
`protected`. Overriding an internal method works today but is not supported.

| Override (`protected`) | Purpose |
| --- | --- |
| `shouldAutosave()` | Enable or disable autosave for this component |
| `autosaveDebounce()` / `autosaveExcept()` | Per-page debounce and excluded fields |
| `autosavePollInterval()` | Per-page poll interval for other editors' changes; `0` disables |
| `beforeAutosave(array $data): array` | Inspect or mutate the eligible state before validation |
| `getAutosaveValidationRules()` | Extra rules; failing fields are skipped |
| `afterAutosave(object $record)` | Work after each successful Edit-page save |
| `getUndoTtlMinutes()` | Undo snapshot lifetime |
| `resolveAutosaveForm()` / `getAutosaveStatePath()` | Which schema and state path autosave uses |
| `persistAutosaveForm(array $data)` | Custom persistence for generic forms |
| `getAutosaveFormContext()` | Draft/Undo scope for generic forms |

| Call (`public`) | Purpose |
| --- | --- |
| `autosave()` | Background save; never throws |
| `flushAutosave(): bool` | Synchronous save that throws |
| `undoAutosave()` | Restore the last autosave |
| `syncAutosave()` | Pull other editors' changes into untouched fields (what the poll calls) |
| `getAutosavePollInterval()` | Resolved poll interval |
| `restoreDraft()` / `discardDraft()` / `clearAutosaveDraft()` | Draft lifecycle |
| `isAutosaveEnabled()` / `getAutosaveDebounce()` / `getAutosaveExcept()` | Resolved settings |

The package's own events (`Lenorix\FilamentAutosave\Events\*`) are the
supported way to observe the lifecycle without overriding anything.

## Undo

Undo is available for five seconds after a successful Edit save. The snapshot
itself is kept for 90 minutes by default; configure that period with
`getUndoTtlMinutes()` or `undo_ttl`.

Undo restores the previous values for:

- model columns, including `BelongsTo` and `MorphTo` keys;
- `BelongsToMany` pivot data;
- `HasOne` and `HasMany` child records;
- supported `HasManyThrough` graphs, including rows that must be restored,
  updated, or removed.

Before restoring anything, Undo checks that the current value still matches the
value written by the autosave. If another user changed that same value, Undo is
cancelled with a conflict status so their update is preserved. Changes to other
columns do not block the one-step Undo.

Undo also runs the relevant Filament save hooks and events and sends the normal
saved notification. It is limited to the current live page instance.
Nested relationship snapshots are bounded by `relationship_undo_depth` (eight
levels by default). If a pending graph exceeds that limit, Undo is disabled for
the cycle instead of restoring only part of the graph.

File operations and RichEditor attachment operations keep Undo disabled by
default because a database transaction cannot roll back filesystem or external
storage changes. Register a reversible `AutosaveExternalUndoAdapter` when the
provider can snapshot, compare, and restore its state; if any changed external
field lacks an adapter, the whole Undo operation remains disabled for safety.

## Forms outside resource pages

Relation managers, action and modal forms, table forms, and standalone Livewire
components can use `HasAutosaveForForm`:

```php
use Lenorix\FilamentAutosave\HasAutosaveForForm;

class EditCommentAction extends RelationManager
{
    use HasAutosaveForForm;

    public ?array $data = [];

    protected function getAutosaveFormContext(): string
    {
        return 'comment:'.($this->ownerRecord->getKey() ?? 'new');
    }
}
```

Call `mountHasAutosaveForForm()` from `mount()` and include the indicator in the
component view:

```blade
@include('filament-autosave::autosave-indicator', [
    'mode' => 'form',
    'debounce' => $autosaveDebounceMs ?? 1500,
])
```

By default, the trait stores drafts for forms without an existing record. When
the resolved schema is bound to an Eloquent record — for example, an Edit
action or modal — it can persist columns and invoke relationship callbacks,
run the standard save lifecycle, and provide one-step Undo for changed columns
and supported relationships with optimistic conflict detection.

Action and table forms often keep their state under `mountedActions.*.data`.
Point the trait to the active schema and state path when necessary:

```php
protected function resolveAutosaveForm(): ?object
{
    return $this->getMountedActionSchema();
}

protected function getAutosaveStatePath(): string
{
    return 'mountedActions.'.array_key_last($this->mountedActions).'.data';
}
```

Override `persistAutosaveForm()` when the component has a custom action
lifecycle or side effects beyond the form schema. Use a context that identifies
the owner, record, or action so unrelated forms never share a draft.
Set `require_form_context` to `true` to turn a missing context into a
`LogicException`; this is recommended for reusable Relation Manager, action,
modal, and table-form components.

Filament's `RecordUpdated`/`RecordSaved` events are Edit-page only: they
require a real `Filament\Resources\Pages\Page`, which a relation manager,
action, or other generic component is not, so `HasAutosaveForForm` does not
dispatch them at all. Use `afterAutosave()` or the package's own hooks for
work that needs to run after a generic form save.

Record-backed generic forms use the same upload lifecycle as Edit pages, while
recordless drafts never store permanent files or media. Generic Undo snapshots
cover model columns and supported relationship state. File, media, and
RichEditor attachment operations remain outside Undo unless a reversible
external adapter is registered; host actions still own any additional side
effects.

External Undo can be enabled safely for a provider by registering an
`AutosaveExternalUndoAdapter` in `external_undo_adapters`. The adapter must
implement `supports`, `snapshot`, `matches`, and `restore`. Fields without a
reversible adapter keep Undo disabled.

For a generic form with `dirty_only` enabled, each partial autosave is merged
into its existing draft so earlier field changes remain available. Empty and
`null` values are retained as explicit deletions.

## File uploads

Edit pages get upload support automatically through `HasAutosave`; they do not
need to use `HasAutosaveUploads` directly.

`FileUpload` supports new files, removal, and reordering. Upload validation runs
before permanent storage, including file size and type rules. An invalid upload
leaves its entire column untouched, while unrelated fields can still save.

The following are skipped:

- `storeFiles(false)` uploads;
- disabled or hidden upload fields;
- excluded fields;
- incomplete nested containers.

The controller waits for active uploads to finish, and its request-end hash also
detects server-side actions such as removing a row or reordering files.
Livewire's temporary upload is used as the staging area until validation
passes. Permanent paths are tracked until the owning database transaction has
run its `afterCommit` callbacks, so a rollback can remove every path created by
the cycle.

`SpatieMediaLibraryFileUpload` uses its relationship callback for additions,
removals, and ordering. Unchanged collections are not synchronised. Install
Filament's Spatie plugin in the host application to use it; the plugin is only a
development dependency of this package.

Upload fields inside a `Repeater` bound to a relationship are persisted
together with that relationship, on Edit pages and record-backed generic forms. Media in an existing row is attached
to the row's own record; media in a new row is attached once the relationship
component has created the row. If any field in the repeater fails validation,
the whole relationship write is skipped and no file is stored. Media inside a
repeater stored in a JSON column is not autosaved, because every row would
share the parent record's media collection.

Autosaves involving files keep Undo disabled by default. A registered external
adapter can opt a provider into reversible Undo; without one, a later
validation, hook, relationship, or database write failure still triggers
cleanup of new paths and tracked media where the provider supports it.
Additional side effects performed by a custom storage callback remain the
application's responsibility. Removing a normal `FileUpload` path updates the
column, and physical deletion follows the component's configured behaviour.

Newly stored paths are also recorded in a short-lived cleanup ledger. Register
the pruning command in the host scheduler so an interrupted PHP process cannot
leave those paths indefinitely:

```php
$schedule->command('filament-autosave:prune-uploads')->everyThirtyMinutes();
```

The ledger is a recovery net for storage providers; database and filesystem
transactions still cannot commit as one distributed transaction. A Spatie
Media Library file is journaled right after its relationship callback
returns, because the package cannot know the filename Spatie generates
before that call runs; a process killed between that write and the journal
entry (not a normal exception, which the request-local cleanup above still
catches) can still leave a file with no ledger entry.

## Configuration

Values are resolved in this order: config, plugin, then page. The last value
wins, while `except` entries are merged across levels.

| Option | Config | Plugin | Page |
| --- | :---: | :---: | :---: |
| `debounce` (milliseconds) | Yes | Yes | Yes |
| `except` (field names) | Yes | Yes | Yes |
| `draft_ttl` (hours) | Yes | Yes | No |
| `undo_ttl` (minutes) | Yes | Yes | No |
| `dirty_only` | Yes | No | No |
| `refresh_unchanged_fields` | Yes | No | No |
| `poll_interval` (milliseconds, 0 = off) | Yes | Yes | Yes |
| `require_form_context` | Yes | No | No |
| `relationship_undo_depth` | Yes | No | No |
| `external_undo_adapters` | Yes | No | No |
| `upload_ledger_ttl` (minutes) | Yes | No | No |
| `show_saved_at` | Yes | Yes | No |
| `position` | Yes | Yes | No |
| `exceptPages` | No | Yes | No |

Page settings are methods. Do not redeclare properties supplied by either
trait; conflicting trait properties cause a PHP fatal error.

```php
AutosavePlugin::make()
    ->debounce(2000)
    ->cacheTtl(48)                         // draft lifetime in hours
    ->undoCacheTtl(45)                     // snapshot lifetime in minutes
    ->except(['internal_notes'])
    ->exceptPages([EditPayment::class])
    ->showTimestamp(false)
    ->indicatorPosition('after');
```

Equivalent page and config settings include:

```php
protected function autosaveDebounce(): ?int
{
    return 2000;
}

protected function autosaveExcept(): array
{
    return ['internal_notes'];
}

protected function shouldAutosave(): bool
{
    return true;
}
```

```php
// config/filament-autosave.php
'debounce' => 1500,
'except' => ['password', 'password_confirmation'],
'draft_ttl' => 24,
'undo_ttl' => 90,
'dirty_only' => true,
'refresh_unchanged_fields' => true,
'require_form_context' => false,
'relationship_undo_depth' => 8,
'external_undo_adapters' => [],
'upload_ledger_ttl' => 180,
```

`shouldAutosave()` is evaluated server-side and cannot be changed from the
browser. The indicator accepts `before` or `after` as its position; when a page
has no usable header, it is rendered at the end of the page.

### Dirty-only updates

With `dirty_only` enabled, Edit autosave tracks a hash for each top-level field
and writes only fields that changed since the last successful save. Hashes
survive Livewire requests, while the original values do not need to be kept in
memory. Manual saves and Undo reset the baseline.

`refresh_unchanged_fields` can refresh untouched model-backed fields after a
successful autosave, on Edit pages and on record-backed generic forms
(`HasAutosaveForForm`); recordless drafts have nothing to refresh from. Local
dirty values remain in the form, and relationship, upload, and excluded fields
are never refreshed. This is a response-time refresh, not polling, and
concurrent edits to the same column are still last-write-wins. Set `dirty_only` to `false` to send the complete eligible
column payload instead. File operations remain limited to changed upload fields
in either mode.

Nested groups and repeaters are compared as one value when they are stored in a
single database column.

### Live updates by polling

`refresh_unchanged_fields` only runs when *this* user saves. With
`poll_interval` (default 5000 ms; `0` disables it) the browser also asks the
server every few seconds, through `syncAutosave()`, whether another editor has
written to the record, and pulls those changes into the fields this user is
not touching. It is the closest thing to live collaboration without
WebSockets, and the same field rules a future push transport will use.

What a poll does:

- refills clean, model-backed columns whose value changed on the record, using
  the same eligibility rule as the post-save refresh (never relationships,
  uploads, excluded fields, or anything not in `attributesToArray()`);
- lists fields that are dirty locally **and** changed remotely as `stale` in the
  indicator, without touching the local value — the user keeps what they typed;
- reports both in the `autosave-status` Livewire event (`status: synced`,
  `refreshed`, `stale`) and in the `AutosaveSynced` package event;
- stays silent when nothing changed, so an idle page never flickers.

What it never does: write to the database, touch Undo snapshots, or overwrite a
field the user is editing.

The browser pauses polling while a save is pending or in flight, while the tab
is hidden (and syncs immediately when it becomes visible again), and backs off
exponentially after three consecutive failed polls, up to one minute.

Cost: an idle poll adds a single query that reads the record's own columns —
one `updated_at` read when the model has timestamps — whatever the form looks
like. Only when another editor did write does it dehydrate the form to refill
fields, which costs about one query per relationship field, the same as the
post-save refresh. `tests/Integration/AutosaveSyncQueryBudgetTest.php` pins
those ceilings.

```php
AutosavePlugin::make()->pollInterval(10_000);

// or per page / component
protected function autosavePollInterval(): ?int
{
    return 0; // this form never polls
}
```

Polling applies to Edit pages and record-backed generic forms; drafts and
Create pages have no record to sync from and expose `autosavePollMs = 0`.

## Sensitive data

Autosave removes or skips:

- temporary uploads in Create/custom-page drafts;
- password fields marked with `password()` or `type('password')`, at any depth;
- fields listed in `except`;
- client keys that are not declared form fields, at any depth.

The `except` list matches top-level names. For nested secrets, use a password
field or `dehydrated(false)`. Create drafts read raw form state before autosave
validation and dehydration transforms, so explicitly exclude any secret that
must never be cached.

## Translations

```bash
php artisan vendor:publish --tag="filament-autosave-translations"
```

Available labels include `unsaved`, `saving`, `saved`, `saved_at`, `undo`,
`undone`, `conflict`, `error`, `draft_available`, `restore`, `discard`, and
`restored`. Validation messages use the `validation` translation key and the
indicator lists their `pending` field paths.

## Testing

```bash
composer test
vendor/bin/pest --testsuite=Unit
vendor/bin/pest --testsuite=Integration
```

`tests/Browser` drives the real panel in Chromium through Pest's browser
plugin and is excluded from `composer test`. It needs Node only for the
Playwright browser driver (`npm ci && npx playwright install chromium`), then:

```bash
composer test:browser
```

## License

[The Unlicense](LICENSE.md)
