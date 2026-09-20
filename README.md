# Filament Autosave

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lenorix/filament-autosave.svg?style=flat-square)](https://packagist.org/packages/lenorix/filament-autosave)
[![Total Downloads](https://img.shields.io/packagist/dt/lenorix/filament-autosave.svg?style=flat-square)](https://packagist.org/packages/lenorix/filament-autosave)
[![Tests](https://img.shields.io/github/actions/workflow/status/lenorix/filament-autosave/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/lenorix/filament-autosave/actions/workflows/tests.yml)
[![License](https://img.shields.io/badge/license-Unlicense-blue.svg?style=flat-square)](LICENSE.md)

Autosave for Filament forms. Stop typing for a moment, and what you changed is already in the database,
column by column. Close the tab and nothing is lost. Two people can work on the same record without
stepping on each other. And if a save was a mistake, one click undoes it.

```php
class EditArticle extends EditRecord
{
    use HasAutosave;
}
```

**What you get**

- **Dirty-only saves**: Writes only modified columns; syncs remote changes on untouched fields.
- **Conflict-safe Undo**: One-step undo that aborts if the record changed in the meantime.
- **Create drafts**: Restorable drafts on Create pages without persisting records prematurely.
- **Media & nested relations**: Supports repeaters at any depth, file uploads, and Spatie Media Library with failure cleanup.
- **Broad compatibility**: Works on Edit/Create pages, relation managers, actions, modals, table forms, and Livewire components.
- **Easy to extend**: Lifecycle events and hooks for custom behavior.

**Works with** Spatie Media Library (`filament/spatie-laravel-media-library-plugin`) and translatable Edit pages
(`lara-zeus/spatie-translatable`) out of the box when installed. No Node build or custom stylesheet.

## Requirements

- PHP 8.4 or 8.5
- Laravel 12 or 13
- Filament 4.13 or later, or Filament 5
- Livewire 3 with Filament 4, or Livewire 4 with Filament 5

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

That's all a resource page needs. The status indicator is added for you; see [The indicator](#the-indicator) if you're
curious how it's built.

Publish the config, translations, or views only if you want to change them:

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

Modified fields are saved after 750 ms without input. The indicator shows the save status and provides a short Undo window.

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

Users can restore or discard drafts. Drafts survive validation failures and are removed after the record is created.
They do not create records or permanently store files and media until the form is submitted.

On custom pages that manage `$data`, clear the draft after a successful save:

```php
public function save(): void
{
    // Persist form state...
    $this->clearAutosaveDraft();
}
```

### Relation managers

Add `HasAutosaveForRelationManager` to autosave edit and create modals in relation managers:

```php
use Filament\Resources\RelationManagers\RelationManager;
use Lenorix\FilamentAutosave\HasAutosaveForRelationManager;

class CommentsRelationManager extends RelationManager
{
    use HasAutosaveForRelationManager;

    protected static string $relationship = 'comments';
}
```

The trait detects the relation-manager form and keeps drafts and Undo data separate for each record and modal.

Clear create drafts in the `CreateAction` once the record is created:

```php
CreateAction::make()
    ->after(fn (CommentsRelationManager $livewire) => $livewire->clearAutosaveDraft());
```

### Actions, modals, table forms, and Livewire components

Use `HasAutosaveForForm` for action and modal forms, table forms, and standalone Livewire components:

```php
use Lenorix\FilamentAutosave\HasAutosaveForForm;
use Livewire\Component;

class EditCommentForm extends Component
{
    use HasAutosaveForForm;

    public ?array $data = [];
    public string|int|null $recordId = null;

    protected function getAutosaveFormContext(): string
    {
        return 'comment:'.$this->recordId;
    }
}
```

Return a stable value based on the owner, record, or action so separate forms do not share drafts.

Call `$this->mountHasAutosaveForForm()` in `mount()` and include the indicator in the Blade view:

```blade
@include('filament-autosave::autosave-indicator', [
    'mode' => 'form',
    'debounce' => $autosaveDebounceMs ?? 1500,
])
```

Record-backed forms autosave columns, run relationship hooks, and support Undo. Recordless forms store drafts.

Each form needs a unique context so drafts from different records or actions do not collide. Enable
`require_form_context` to require this explicitly.

For action or table forms whose state lives under `mountedActions.*.data`, provide the form schema and state path:

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

- **Custom persistence**: Override `persistAutosaveForm()` for custom action lifecycles or side effects.
- **Page events**: Filament's `RecordSaved`/`RecordUpdated` require a `Page`; use `afterAutosave()` or package events.

## What autosave saves

Autosave writes changed, valid form data and leaves the rest of the record alone.

- Regular fields, JSON and relationship repeaters, uploads, media, and `RichEditor` are supported.
- Password fields, fields with `dehydrated(false)`, and fields listed in `except` are never saved.
- With dirty-only enabled, each cycle writes only fields that changed.
- Invalid fields are skipped without clearing their stored value; valid sibling fields can still be saved.
- Groups, builders, and other single-column fields are saved only when all of their child inputs are valid.
- Filament hooks, events, and page authorization continue to run for autosave, sync, and Undo.

```php
protected function getAutosaveValidationRules(): array
{
    return ['slug' => ['required', 'string', 'max:120']];
}

protected function afterAutosave(object $record): void
{
    Cache::forget("user-{$record->id}");
}
```

Use `getAutosaveValidationRules()` for rules that apply only to autosave. Use `afterAutosave()` for work that should
run after a successful Edit-page save.

## Keeping editors in sync

Dirty-only saves are enabled by default. Changes to different fields do not overwrite each other. Changes to the same
field use last-write-wins unless text merging is enabled.

### Refresh after saving

When `refresh_unchanged_fields` is enabled (the default), each autosave refreshes untouched model columns in its
response. This does not use polling and never replaces values you are currently editing. Relationships, uploads, and
excluded fields are not refreshed by this option.

### Background polling

Set `poll_interval` to check for remote changes in the background. It defaults to 5 seconds; set it to `0` to disable
polling. Polling requires `refresh_unchanged_fields` to be enabled.

Polling updates untouched columns, repeaters, uploads, and media. Fields with local changes are marked as `stale` and
are never overwritten.

By default, polling also checks relationships. Disable this with `poll_relationships` or
`pollRelationships(false)`.

Polling is available on Edit pages and record-backed forms.

```php
AutosavePlugin::make()
    ->pollInterval(10_000)
    ->pollRelationships(false);

// Disable polling for one page or component
protected function autosavePollInterval(): ?int
{
    return 0;
}
```

### Merging text edits

Add top-level text fields to `mergeFields` to combine non-overlapping edits from different users:

```php
AutosavePlugin::make()->mergeFields(['title', 'body']);

// Or per page / component
protected function autosaveMergeFields(): ?array
{
    return ['title'];
}
```

Supported fields are `TextInput`, `Textarea`, `MarkdownEditor`, and `RichEditor`.

Non-overlapping changes are merged automatically. If both users change the same text, last-write-wins applies and the
previous version can be restored from the conflict notice. Undo also restores the state from before the merge.

## Undo

Available for 5 seconds after a save. Snapshots persist for 90 minutes by default (`undo_ttl` / `getUndoTtlMinutes()`).

- **Restored state**: Attributes, pivot data, and relationships (`BelongsTo`, `MorphTo`, `HasOne`, `HasMany`, `HasManyThrough`).
- **Conflict detection**: Aborts if restored fields changed remotely; edits to other columns do not block Undo.
- **Lifecycle**: Executes standard Filament save hooks, events, and notifications on the active page.
- **Files & media**: Excluded by default unless registered via `external_undo_adapters` (`AutosaveExternalUndoAdapter`).
- **Depth limit**: Snapshots relations up to `relationship_undo_depth` (default: 8); deeper graphs disable Undo.

## Uploads and media

`HasAutosave` supports Filament's `FileUpload` and Spatie Media Library fields. It handles new files, removals, and
reordering automatically.

### File uploads

- File size and MIME type are checked before saving.
- If one upload is invalid, the other fields can still be saved.
- Fields with `storeFiles(false)`, or fields that are hidden, disabled, or excluded, are skipped.

### Spatie Media Library

Install `filament/spatie-laravel-media-library-plugin` to enable support.

Media in relationship repeaters is attached after the child record is created. If validation fails, the media is not
saved.

In JSON repeaters, each row must use its own collection. A UUID is a simple way to create unique collection names:

```php
Repeater::make('settings')->schema([
    Hidden::make('uuid')->default(fn (): string => (string) Str::uuid()),
    TextInput::make('label'),
    SpatieMediaLibraryFileUpload::make('images')
        ->multiple()
        ->collection(fn (Get $get): string => 'row_'.$get('uuid')),
]),
```

### Failed and interrupted uploads

Edit and Create page autosaves use database transactions. Generic forms use the host transaction when one is
available. When a save fails, newly uploaded files and media are removed automatically.

To clean up files left behind by interrupted requests, schedule the pruning command:

```php
$schedule->command('filament-autosave:prune-uploads')->everyThirtyMinutes();
```

## Explicit saves with `flushAutosave()`

`autosave()` runs in the background and never throws. For explicit actions (buttons, modals), `flushAutosave()` runs
the same dirty-only save, refresh, and Undo while surfacing errors directly to the caller.

```php
Action::make('generateSlug')
    ->action(function (): void {
        $this->data['slug'] = Str::slug($this->data['title']);

        $written = $this->flushAutosave();
    });
```

`flushAutosave()` runs synchronously and returns whether changes were written (`bool`). Validation errors throw a
`ValidationException` keyed by state path (`data.title`) for inline display. Exceptions from hooks, rules, or
persistence propagate untouched, as does Filament's `Halt`. Available across all autosave traits.

## Events

Dispatches typed Laravel event objects (`record` is `null` for drafts and Create pages).

| Event (`Lenorix\FilamentAutosave\Events\…`) | Payload | When |
| --- | --- | --- |
| `AutosaveSaved` | `page`, `record`, `data`, `pending`, `merged`, `conflicts` | A cycle wrote something |
| `AutosaveSkipped` | `page`, `reason` (`validation` or `unchanged`), `pending`, `errors` | Nothing was written |
| `AutosaveFailed` | `page`, `exception`, `context` (`save`, `sync`, `undo` or `restore`) | An exception was swallowed |
| `AutosaveUndone` | `page`, `record` | Undo restored the snapshot |
| `AutosaveConflict` | `page`, `record`, `conflicts` | Undo backed off because the record changed elsewhere, or a mergeable field stayed contended |
| `AutosaveSynced` | `page`, `record`, `refreshed`, `stale`, `patches` | A poll pulled another editor's changes |

```php
Event::listen(AutosaveFailed::class, function (AutosaveFailed $event): void {
    report($event->exception);
});
```

## Customizing autosave

Use the documented hooks and methods below when the defaults do not fit your form. Other trait internals are not part
of the public API and may change between releases.

| Override (`protected`) | Purpose |
| --- | --- |
| `shouldAutosave()` | Enable or disable autosave for this component |
| `autosaveDebounce()` / `autosaveExcept()` | Set the debounce delay or excluded fields for one page |
| `autosavePollInterval()` | Set the polling interval for one page; `0` disables polling |
| `autosaveMergeFields()` | Choose fields to merge when other editors change them |
| `beforeAutosave(array $data): array` | Change the data before validation |
| `getAutosaveValidationRules()` | Add rules used by autosave |
| `afterAutosave(object $record)` | Run code after a successful Edit-page save |
| `getUndoTtlMinutes()` | Change how long Undo remains available |
| `resolveAutosaveForm()` / `getAutosaveStatePath()` | Select the form and state path for generic forms |
| `persistAutosaveForm(array $data)` | Provide custom persistence for generic forms |
| `getAutosaveFormContext()` | Isolate drafts and Undo data for generic forms |

| Call (`public`) | Purpose |
| --- | --- |
| `autosave(array $mergePatches = [])` | Run a background save; errors are reported through the indicator |
| `flushAutosave(): bool` | Run a save immediately and let errors propagate |
| `undoAutosave()` | Restore the most recent autosave |
| `syncAutosave(array $mergeBaseHashes = [])` | Pull remote changes into untouched fields |
| `restoreDraft()` / `discardDraft()` / `clearAutosaveDraft()` | Manage a Create or recordless draft |

Use package events (`Lenorix\FilamentAutosave\Events\*`) when you need to observe the lifecycle without overriding
methods.

## Configuration

Resolution order: **config → plugin → page** (last wins, except `except` which merges across all three).

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

Options without a plugin or page method are set in the published config file, including `dirty_only`,
`refresh_unchanged_fields`, `poll_interval`, and `poll_relationships`.

Page-level settings use methods (do not redeclare trait properties):

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
    return true; // Evaluated on server
}
```

## Translations

```bash
php artisan vendor:publish --tag="filament-autosave-translations"
```

English is included by default. The translations cover status badges, draft and Undo actions, validation messages, and
pending field indicators.

## The indicator

- **Position**: `before` or `after` header (`position` / `indicatorPosition()`), or page bottom.
- **Elements**: Save timestamp (`show_saved_at`), pending/stale alerts, and undo action.
- **Generic forms**: `@include('filament-autosave::autosave-indicator', ['mode' => 'form'])`.

## License

[The Unlicense](LICENSE.md)

## Support It

If you find this package useful, consider starring the repository on [GitHub](https://github.com/lenorix/filament-autosave),
joining our [Discord](https://discord.gg/uGxpY7PQGa), or supporting [Lenorix](https://github.com/lenorix) open-source projects.
