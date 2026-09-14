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
components. No extra frontend build step is required.

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
| `FileUpload` backed by a column, including nested fields | Yes, after upload validation |
| Top-level `SpatieMediaLibraryFileUpload` | Yes, changed collections only |
| `FileUpload` or `SpatieMediaLibraryFileUpload` inside a relationship `Repeater` row (Edit pages) | Yes, with the row's relationship write |
| `SpatieMediaLibraryFileUpload` inside a JSON (non-relationship) repeater | No |
| Relationships inside groups, repeaters, and builders | Yes, when the relationship changes |
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

File operations and RichEditor attachment operations do not offer Undo: a
database transaction cannot roll back changes already made to filesystem or
external storage.

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

Record-backed generic forms use the same upload lifecycle as Edit pages, while
recordless drafts never store permanent files or media. Generic Undo snapshots
cover model columns and supported relationship state; file, media, and
RichEditor attachment operations remain outside Undo and should be coordinated
by the host action when they have additional side effects.

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

`SpatieMediaLibraryFileUpload` uses its relationship callback for additions,
removals, and ordering. Unchanged collections are not synchronised. Install
Filament's Spatie plugin in the host application to use it; the plugin is only a
development dependency of this package.

On Edit pages, upload fields inside a `Repeater` bound to a relationship are
persisted together with that relationship. Media in an existing row is attached
to the row's own record; media in a new row is attached once the relationship
component has created the row. If any field in the repeater fails validation,
the whole relationship write is skipped and no file is stored. Media inside a
repeater stored in a JSON column is not autosaved, because every row would
share the parent record's media collection. `HasAutosaveForForm` keeps nested
media as an explicit-save concern.

Autosaves involving files do not provide Undo. If a later validation, hook,
relationship, or database write fails, the package cleans up new paths and
tracked media it created where the storage provider supports it. Additional
side effects performed by a custom storage callback remain the application's
responsibility. Removing a normal `FileUpload` path updates the column, and
physical deletion follows the component's configured behaviour.

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
successful autosave. Local dirty values remain in the form. This is a
response-time refresh, not polling, and concurrent edits to the same column are
still last-write-wins. Set `dirty_only` to `false` to send the complete eligible
column payload instead. File operations remain limited to changed upload fields
in either mode.

Nested groups and repeaters are compared as one value when they are stored in a
single database column.

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

## License

[The Unlicense](LICENSE.md)
