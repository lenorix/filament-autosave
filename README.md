# Filament Autosave

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lenorix/filament-autosave.svg?style=flat-square)](https://packagist.org/packages/lenorix/filament-autosave)
[![Total Downloads](https://img.shields.io/packagist/dt/lenorix/filament-autosave.svg?style=flat-square)](https://packagist.org/packages/lenorix/filament-autosave)
[![Tests](https://img.shields.io/github/actions/workflow/status/lenorix/filament-autosave/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/lenorix/filament-autosave/actions/workflows/tests.yml)
[![License](https://img.shields.io/badge/license-Unlicense-blue.svg?style=flat-square)](LICENSE.md)
[![Plumb score](https://plumbphp.dev/badges/lenorix/filament-autosave/composite.svg)](https://plumbphp.dev/lenorix/filament-autosave)

Filament Autosave saves form changes after the user pauses typing. It writes only the fields that changed, keeps
untouched fields up to date when other editors save, and offers one-step Undo.

It works with Edit and Create pages, relation managers, actions, modals, table forms, and standalone Livewire forms.
Optional integrations support Spatie Media Library (`filament/spatie-laravel-media-library-plugin`) and translatable
Edit pages (`lara-zeus/spatie-translatable`).

## Requirements

- PHP 8.4 or 8.5
- Laravel 12 or 13
- Filament 4.13+ or 5
- Livewire 3 with Filament 4, or Livewire 4 with Filament 5

## Contributing

Contributions are welcome. Please read [CONTRIBUTING.md](https://github.com/lenorix/filament-autosave/blob/main/CONTRIBUTING.md) before opening an issue or a pull request.

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

Edit pages now autosave with the default settings. Publish the config, translations, or views only when you need to
change them. No Node build step is required:

```bash
php artisan vendor:publish --tag="filament-autosave-config"
php artisan vendor:publish --tag="filament-autosave-translations"
php artisan vendor:publish --tag="filament-autosave-views"
```

## Add autosave to a form

### Edit pages

```php
use Filament\Resources\Pages\EditRecord;
use Lenorix\FilamentAutosave\HasAutosave;

class EditArticle extends EditRecord
{
    use HasAutosave;

    protected static string $resource = ArticleResource::class;
}
```

Modified fields are saved after 750 ms without input. The indicator and Undo action are added automatically.

### Create pages

Use `HasAutosaveForCreate` to keep an unfinished form as a draft:

```php
use Filament\Resources\Pages\CreateRecord;
use Lenorix\FilamentAutosave\HasAutosaveForCreate;

class CreateArticle extends CreateRecord
{
    use HasAutosaveForCreate;

    protected static string $resource = ArticleResource::class;
}
```

Users can restore or discard drafts. Drafts survive validation errors and are removed after the record is created. They
do not create records or permanently store files and media until the form is submitted.

For a custom page that stores its form in `$data`, call `$this->clearAutosaveDraft()` after a successful save.

### Relation managers

Add `HasAutosaveForRelationManager` to autosave create and edit modals:

```php
use Filament\Resources\RelationManagers\RelationManager;
use Lenorix\FilamentAutosave\HasAutosaveForRelationManager;

class CommentsRelationManager extends RelationManager
{
    use HasAutosaveForRelationManager;

    protected static string $relationship = 'comments';
}
```

Clear a creation draft after the action creates its record:

```php
CreateAction::make()
    ->after(fn (CommentsRelationManager $livewire) => $livewire->clearAutosaveDraft());
```

### Actions, modals, table forms, and Livewire components

Use `HasAutosaveForForm` for forms outside resource pages:

```php
use Livewire\Component;
use Lenorix\FilamentAutosave\HasAutosaveForForm;

class EditCommentForm extends Component
{
    use HasAutosaveForForm;

    public ?array $data = [];
    public string|int|null $recordId = null;

    public function mount(): void
    {
        $this->mountHasAutosaveForForm();
    }

    protected function getAutosaveFormContext(): string
    {
        return 'comment:'.$this->recordId;
    }
}
```

The context must be stable and unique for the owner, record, or action. This keeps drafts and Undo snapshots separate.
Enable `require_form_context` to reject forms without an explicit context.

Include the indicator in the component view:

```blade
@include('filament-autosave::autosave-indicator', ['mode' => 'form'])
```

For action forms whose state lives under `mountedActions.*.data`, override `resolveAutosaveForm()` and
`getAutosaveStatePath()` to return the active schema and state path.

## What gets saved

Autosave writes changed, valid form data and leaves the rest of the record alone.

- Regular fields, JSON and relationship repeaters, uploads, media, and `RichEditor` are supported.
- Password fields, `dehydrated(false)`, and fields listed in `except` are never saved.
- With `dirty_only` enabled (the default), each cycle writes only fields that changed.
- Invalid fields are skipped without clearing their stored value; valid sibling fields can still be saved.
- Groups, builders, and other single-column fields are saved only when all child inputs are valid.
- Filament validation, hooks, events, and page authorization still run.

Add autosave-only rules or a post-save hook when needed:

```php
protected function getAutosaveValidationRules(): array
{
    return ['slug' => ['required', 'string', 'max:120']];
}

protected function afterAutosave(object $record): void
{
    // Run work after a successful Edit-page autosave.
}
```

## Keep editors in sync

Different fields can be edited at the same time without overwriting each other. Changes to the same field use
last-write-wins unless text merging is enabled.

### Refresh and polling

`refresh_unchanged_fields` refreshes untouched model columns in the save response. It is enabled by default and does
not replace values the current user is editing.

Set `poll_interval` to check for remote changes in the background. It defaults to 5 seconds; `0` disables polling.
Polling requires `refresh_unchanged_fields` and is available on Edit pages and record-backed forms.

Polling updates untouched columns, repeaters, uploads, and media. Locally edited fields are marked `stale` instead of
being overwritten. Nested relationship fields are fingerprinted up to `poll_relationship_depth` (3 by default), so
deep child changes no longer require `$touches` on the parent. Set the depth to `0` for direct relationships only.
Timestamp-free relations hash their rows up to `poll_relationship_max_rows` (500 by default); larger relations use a
cheap key/count check, so timestamp columns are recommended for large collections.
Set `poll_relationships` to `false` or call `pollRelationships(false)` for column-only polling.

### Merge text fields

Configure top-level `TextInput`, `Textarea`, `MarkdownEditor`, or `RichEditor` fields to merge non-overlapping edits:

```php
AutosavePlugin::make()
    ->mergeFields(['title', 'body'])
    ->pollInterval(10_000);
```

Overlapping changes still use last-write-wins. The previous version can be restored from the conflict notice, and Undo
restores the state from before the merge.

## Undo

Undo is available for 5 seconds after a save. Snapshots are kept for 90 minutes by default.

- Restores saved attributes, pivot data, and supported relationships.
- Stops when the fields being restored changed remotely, so Undo does not overwrite newer work.
- Relation snapshots are limited by `relationship_undo_depth` (8 by default).
- Files and media are excluded unless a reversible `external_undo_adapters` adapter is configured.

## Uploads and media

Native `FileUpload` fields support uploads, removals, reordering, and size/MIME validation. Invalid uploads skip only
their field. Hidden, disabled, excluded, and `storeFiles(false)` fields are skipped.

Spatie Media Library support requires:

```bash
composer require filament/spatie-laravel-media-library-plugin
```

Media in relationship repeaters is attached after the child record is created. In JSON repeaters, use a unique media
collection per row, for example:

```php
Repeater::make('settings')->schema([
    Hidden::make('uuid')->default(fn (): string => (string) Str::uuid()),
    SpatieMediaLibraryFileUpload::make('images')
        ->multiple()
        ->collection(fn (Get $get): string => 'row_'.$get('uuid')),
]),
```

Edit and Create page autosaves use database transactions. Generic forms use the host transaction when one is available.
Failed saves remove newly stored files and media. Schedule cleanup for files left by interrupted requests:

```php
$schedule->command('filament-autosave:prune-uploads')->everyThirtyMinutes();
```

## Explicit saves

Use `flushAutosave()` when a button, action, or modal must save immediately:

```php
$written = $this->flushAutosave(); // bool
```

It runs the same dirty-only save, refresh, and Undo flow synchronously. Validation and persistence exceptions are
thrown to the caller. The background `autosave()` method reports failures through the indicator instead.

For a mounted action or modal, autosave also runs the action's form-validation callbacks, data mutator, and
`before`/`after` callbacks. The action's own submit callback remains submit-only. A background autosave never sends the
action's success notification or redirects; `flushAutosave()` sends them after its write commits.

## Configuration

Plugin settings override the published config, and page settings override the plugin:

```php
AutosavePlugin::make()
    ->debounce(2_000)
    ->pollInterval(10_000)
    ->pollRelationships(false)
    ->mergeFields(['title'])
    ->except(['internal_notes'])
    ->showTimestamp(false)
    ->indicatorPosition('after');
```

Publish the config to change options without a plugin method, such as `dirty_only`, `refresh_unchanged_fields`,
`poll_interval`, `poll_relationships`, `relationship_undo_depth`, and `require_form_context`.

## Translations and indicator

Publish translations with `filament-autosave-translations`. English is included by default.

The indicator shows save status, timestamps, pending or stale fields, validation messages, and Undo. It is added
automatically on resource pages. Generic forms include it with:

```blade
@include('filament-autosave::autosave-indicator', ['mode' => 'form'])
```

Package lifecycle events are available under `Lenorix\FilamentAutosave\Events` when the application needs to react to
saves, failures, synchronization, or Undo.

## License

[The Unlicense](https://github.com/lenorix/filament-autosave/blob/main/LICENSE.md)
