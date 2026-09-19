# Filament Autosave

[![Latest Version on Packagist](https://img.shields.io/packagist/v/lenorix/filament-autosave.svg?style=flat-square)](https://packagist.org/packages/lenorix/filament-autosave)
[![Total Downloads](https://img.shields.io/packagist/dt/lenorix/filament-autosave.svg?style=flat-square)](https://packagist.org/packages/lenorix/filament-autosave)
[![Tests](https://img.shields.io/github/actions/workflow/status/lenorix/filament-autosave/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/lenorix/filament-autosave/actions/workflows/tests.yml)
[![License](https://img.shields.io/badge/license-Unlicense-blue.svg?style=flat-square)](LICENSE.md)

Autosave for Filament forms. Stop typing for a moment and what you changed is
already in the database, column by column. Close the tab and nothing is lost.
Two people can work on the same record without stepping on each other. And if
a save was a mistake, one click undoes it.

```php
class EditArticle extends EditRecord
{
    use HasAutosave;
}
```

**What you get**

- Only the columns you touched are written. The fields you didn't touch pick
  up other people's changes after each save and on a periodic poll.
- One-step Undo that steps aside instead of overwriting when someone else
  changed the record in the meantime.
- Drafts on Create pages that the user can restore or throw away.
- Relationship repeaters at any depth, file uploads and Spatie Media Library,
  with cleanup when something fails and a ledger to recover from crashes.
- Works on Edit and Create pages, relation managers, actions, modals, table
  forms, and plain Livewire components.
- A stable `@api` surface, lifecycle events, and 740+ tests including real
  browser flows.

**Works with** Filament 4 and 5, Laravel 12 and 13, PHP 8.4 and 8.5,
`filament/spatie-laravel-media-library-plugin` and translatable Edit pages
(`lara-zeus/spatie-translatable`). No Node build and no stylesheet of its own.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [How autosave decides what to write](#how-autosave-decides-what-to-write)
- [Keeping editors in sync](#keeping-editors-in-sync)
- [Undo](#undo)
- [Uploads and media](#uploads-and-media)
- [Explicit saves with `flushAutosave()`](#explicit-saves-with-flushautosave)
- [Events](#events)
- [Extension points and stability](#extension-points-and-stability)
- [Configuration](#configuration)
- [Translations](#translations)
- [The indicator](#the-indicator)
- [Testing](#testing)
- [License](#license)

## Requirements

- PHP 8.4 or 8.5
- Laravel 12 or 13
- Filament 4 or 5
- Livewire 3 with Filament 4, or Livewire 4 with Filament 5

The test suite runs against every combination of those PHP, Filament, and
Laravel versions.

Two Spatie integrations are optional. If your app installs
`filament/spatie-laravel-media-library-plugin`, `SpatieMediaLibraryFileUpload`
fields are autosaved too; if it installs `lara-zeus/spatie-translatable`,
translatable Edit pages work as expected.

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

That's all a resource page needs. The status indicator is added for you; see
[The indicator](#the-indicator) if you're curious how it's built.

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

The browser watches the form for you. After 750 ms without changes (the
`debounce`) it saves whatever is safe to save and updates the indicator. Right
after a save the user gets a few seconds to undo it.

If you need to hook custom frontend code into this, the locked
`autosaveDataPath` property holds the resolved state path.

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

When the user comes back they can restore the draft or discard it. Drafts are
removed after a successful create (also with "Create and create another") and
survive a failed validation.

The same trait works on a custom page that keeps its form in the default
`data` property:

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

A draft is just a draft: it never creates a record, stores a permanent file, or
attaches Spatie media. That only happens when the user actually submits.

### Any other form

Relation managers, action and modal forms, table forms, and standalone Livewire
components use `HasAutosaveForForm`:

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

Call `mountHasAutosaveForForm()` from `mount()` and drop the indicator into the
component's view:

```blade
@include('filament-autosave::autosave-indicator', [
    'mode' => 'form',
    'debounce' => $autosaveDebounceMs ?? 1500,
])
```

What happens next depends on whether there is a record behind the form. Without
one, the trait stores drafts. With one (an Edit action or modal, say) it writes
columns, runs relationship callbacks and the normal save lifecycle, and offers
the same one-step Undo as an Edit page, with the same conflict check.

Pick a context that names the owner, record or action, so two unrelated forms
never share a draft. If you're writing a reusable relation manager, action,
modal, or table form, set `require_form_context` to `true`: a missing context
then throws a `LogicException` instead of silently sharing drafts.

Action and table forms often keep their state under `mountedActions.*.data`.
When that's the case, point the trait at the right schema and path:

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

If the component has its own action lifecycle or side effects beyond the
schema, override `persistAutosaveForm()`.

<details>
<summary>Differences from Edit pages</summary>

- Filament's `RecordUpdated` and `RecordSaved` events are only fired on real
  `Filament\Resources\Pages\Page` instances. A relation manager or action isn't
  one, so `HasAutosaveForForm` doesn't dispatch them. Use `afterAutosave()` or
  the package's own events instead.
- Record-backed generic forms get the same upload lifecycle as Edit pages;
  recordless drafts never store permanent files or media. Undo on a generic
  form covers model columns and supported relationships. Files, media, and
  RichEditor attachments stay outside Undo unless you register a reversible
  external adapter, and any extra side effects of the host action are yours.
- With `dirty_only` on, each partial autosave of a recordless form is merged
  into the existing draft, so earlier changes are kept. Empty and `null`
  values are stored as explicit deletions.

</details>

## How autosave decides what to write

Autosave works from Filament's dehydrated form state: dehydration transforms
are applied and fields marked `dehydrated(false)` are left out.

### What gets saved

| Field | Autosaved? |
| --- | --- |
| Regular field backed by a database column | Yes |
| `Repeater` or `CheckboxList` stored in one column | Yes |
| Relationship field with a top-level `saveRelationships()` callback | Yes, when changed |
| `RichEditor`, with or without a file attachment provider | Yes, as a column; attachments cleaned up via its callback; [mergeable](#rich-text) |
| `FileUpload` backed by a column, including nested fields | Yes, after upload validation |
| Top-level `SpatieMediaLibraryFileUpload` | Yes, changed collections only |
| `FileUpload` or `SpatieMediaLibraryFileUpload` inside a relationship `Repeater` row | Yes, with the row's relationship write |
| `SpatieMediaLibraryFileUpload` inside a JSON (non-relationship) repeater | Yes, when every row resolves its own collection; otherwise pending |
| Relationships inside groups, repeaters and builders | Yes, when the relationship changes |
| Relationship `Repeater` nested inside another relationship `Repeater` (any depth) | Yes; each nested repeater saves its own rows, innermost first |
| Other `dehydrated(false)` fields | No |

A group, section, repeater, or builder that lives in one column is treated as a
single value. It's written only when every child passes the safety checks; if
one child is invalid or half-filled, the column is left as it was. This is also
what stops a container with a password field inside from being saved, while
the rest of the form keeps autosaving.

### Dirty-only writes

With `dirty_only` on, autosave keeps a hash per top-level field and writes only
the fields that changed since the last successful save. The hashes travel with
the Livewire state, so the original values don't have to be kept around. A
manual save or an Undo resets the baseline.

Set `dirty_only` to `false` and every eligible column is sent each time. File
operations are still limited to the upload fields that changed. Nested groups
and repeaters stored in one column are compared as a single value.

### Validation and safety checks

Autosave runs Filament's own validation, then a few extra checks of its own,
plus whatever `getAutosaveValidationRules()` returns:

- A blank required value is skipped, so a `NOT NULL` column is never wiped.
- A `Select`, `CheckboxList` or `ToggleButtons` value has to be one of the
  allowed options, including tenant- or team-specific ones.
- An invalid field is skipped on its own; the valid fields around it still
  save.
- A nested rule such as `items.*.qty` drops the whole top-level container.

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

`beforeAutosave()` sees the complete eligible state, so a cross-field rule can
look at siblings that didn't change. `mutateFormDataBeforeSave()` gets the
column and relationship state after pending Spatie media fields have been
removed. `dirty_only` is applied right before the column write.

On an Edit page a successful autosave runs the full Filament save lifecycle
(`beforeValidate`, `afterValidate`, `beforeSave`, `afterSave`), dispatches
`RecordUpdated` and `RecordSaved`, and shows the usual saved notification.
`afterAutosave()` is the place for work that should run only after an
autosave.

### Sensitive data and authorization

Autosave leaves out:

- temporary uploads in Create and custom-page drafts;
- password fields (`password()` or `type('password')`), at any depth;
- fields listed in `except`;
- keys the browser sends that aren't declared form fields, at any depth.

`except` matches top-level names. For a nested secret use a password field or
`dehydrated(false)`. Create drafts read the raw form state before validation
and dehydration transforms, so exclude explicitly anything that must never be
cached.

`autosave()`, `flushAutosave()`, `syncAutosave()`, `undoAutosave()` and the
draft actions all call the component's `authorizeAccess()` when it exists. The
policies that guard an Edit page guard its autosave too.

## Keeping editors in sync

Two people editing different fields of the same record never overwrite each
other, because only changed columns are written. If they edit the same column,
the last save wins, unless that field is listed for
[merging](#merging-text-edits-from-other-editors).

### Refresh after your own save

With `refresh_unchanged_fields` on, every successful autosave also refreshes the
fields you haven't touched from the record, on Edit pages and on record-backed
generic forms (drafts have nothing to refresh from). Your dirty values stay
put; relationship, upload, and excluded fields are never refreshed. This
happens in the save response, it isn't polling.

### Live updates by polling

That refresh only runs when *you* save. `poll_interval` (default 5000 ms; `0`
turns it off) makes the browser ask the server every few seconds, through
`syncAutosave()`, whether someone else wrote to the record, and pulls those
changes into the fields you aren't touching. It's the closest thing to live
collaboration without WebSockets, and it uses the same field rules a push
transport will use later.

A poll:

- refills clean, model-backed columns whose value changed on the record, with
  the same eligibility rule as the post-save refresh (never relationships,
  uploads, excluded fields, or anything outside `attributesToArray()`);
- marks as `stale` the fields that are dirty locally **and** changed remotely,
  without touching what you typed;
- reports both in the `autosave-status` Livewire event (`status: synced`,
  `refreshed`, `stale`) and in the `AutosaveSynced` package event;
- stays quiet when nothing changed, so an idle page never flickers.

A poll never writes to the database, never touches Undo snapshots, and never
overwrites a field you're editing.

```php
AutosavePlugin::make()->pollInterval(10_000);

// or per page / component
protected function autosavePollInterval(): ?int
{
    return 0; // this form never polls
}
```

Polling applies to Edit pages and record-backed generic forms. Drafts and
Create pages have no record to sync from and expose `autosavePollMs = 0`. It
is also off when `refresh_unchanged_fields` or `dirty_only` is off: a poll
refills through the same refresh, and with `dirty_only` off every save writes
the whole payload anyway (last-write-wins at record level).

<details>
<summary>Browser behaviour and cost</summary>

The browser pauses polling while a save is pending or in flight and while the
tab is hidden (it syncs as soon as the tab comes back). After three failed
polls in a row it backs off exponentially, up to one minute. A save asked for
while a poll is still running waits for that poll and goes out right after
it, so a reply never lands in the middle of the other.

An edit still waiting on its debounce is sent at once when the tab goes to
the background or the page is being left; the unload request is marked
`keepalive` so the browser lets it finish. That last part is best effort:
Livewire sends a few milliseconds after the call, and keepalive bodies are
capped at 64 KB, so a page torn down instantly or a very large form can
still lose the final edit.

An idle poll costs one query that reads the record's own columns, or just
`updated_at` when the model has timestamps, no matter how big the form is.
Only when someone else did write does it dehydrate the form to refill fields,
which is about one query per relationship field, the same as the post-save
refresh. `tests/Integration/AutosaveSyncQueryBudgetTest.php` pins those
ceilings.

</details>

### Merging text edits from other editors

Some fields are the kind two people end up typing in at once: a title, a
summary, a Markdown body, the rich text of an article. List them as mergeable
and concurrent edits are combined, word by word in plain text and block by
block in rich text, instead of the last save replacing the whole thing:

```php
AutosavePlugin::make()->mergeFields(['title', 'body']);

// or per page / component
protected function autosaveMergeFields(): ?array
{
    return ['title'];
}
```

Only top-level `TextInput`, `Textarea`, `MarkdownEditor` and `RichEditor`
fields qualify. Anything else you list is ignored with one warning in the log
and stays last-write-wins.

Here's how it works, with no WebSockets and no state kept on the server:

- Along with the autosave, the browser sends a *patch* of its own change for
  each dirty mergeable field (`diff(base, ours)` in diff-match-patch's text
  format, or the base it started from): `autosave(array $mergePatches)`. The
  server replays that patch on the column's **current** value. Changes to
  different parts of the text from both sides are kept. Where both changed the
  same words, the last save wins in that range only, and the discarded text is
  reported back.
- The merged column is written with a compare-and-swap on that column alone:
  `UPDATE … SET col = merged WHERE id = ? AND col = <the value it was merged
  on>`. If someone else committed in between, the column is read again, merged
  again, and retried after a short wait (5 ms, doubling up to 100 ms), up to
  `merge_retries` times (10 by default, 655 ms of waiting in total). Comparing
  the column instead of a row version means a concurrent writing to *another*
  column never causes a retry, and no row lock is held while people type.
- If a field is still contended after every retry, it isn't written, and
  nothing is lost: the text stays in the form and dirty, the field is
  reported as pending with `reason: contended`, `AutosaveConflict` fires, one
  warning is logged, and the payload carries the merge computed against the
  latest value so the browser can adopt it. The other columns in the same
  cycle are saved and acknowledged as usual.
- A poll (`syncAutosave(array $mergeBaseHashes)`) that finds a dirty mergeable
  field changed remotely still reports it as `stale`, and adds the other
  editor's current value in `patches`, unless the browser already has it (it
  sends back the `hash` it last received).

The guarantee is straightforward: **no other editor's change to a mergeable field is
ever overwritten without being reported**. It's either merged in or listed in
`conflicts`. Fields you don't list keep the column-level last-write-wins rule,
and a save that arrives without a patch (an older browser session, an unlisted
field) behaves exactly as before.

Undo after a merge restores the value the other editor had written, the one
the merge was applied on, never a stale copy. A contended field is left out of
the Undo snapshot, since it was never written.

The cost on the server is one conditional `UPDATE` per merged column, plus one
column read per retry. Livewire already sends every form value with each
request because it lives in `$data`; the patch is small and rides along.

#### Rich text

A `RichEditor` is merged over its document, not its HTML. Both editors'
documents and the stored one are parsed to the same Tiptap tree (Filament
ships `ueberdosis/tiptap-php`, and the merge uses the editor's own extensions,
so custom blocks and plugins are understood) and combined as a tree:

- Blocks are matched by identity, never by position: an image, a mention, a
  merge tag or a custom block by its id; a paragraph or heading by its words.
  One of you inserting a section while the other deletes a paragraph further
  down, or moves a list, just works.
- Inside a paragraph both of you touched, the words are merged like plain
  text, marks included. One of you bolding a sentence while the other fixes a
  typo in it is not a conflict; both of you rewriting the same words is, and
  the later save wins those words only.
- Images, custom blocks, mentions and merge tags are atomic: they stay, move,
  or go as a whole, and are never split or half-merged. Changing an image's
  alt text while someone else removes it is a conflict, resolved like any
  other: the later save wins, and the other version is reported.
- Files are handled with care. The merge runs *before* the editor's own
  attachment cleanup, so an image the other editor still uses is never
  deleted by a save that didn't have it. Only images missing from the merged
  document are cleaned up. What no merge can undo is a deletion that has
  already happened: if someone removed an image and saved, its file is gone,
  even if your later save wins the argument over that node.
- Whatever the column stores, HTML or JSON, the result written is exactly
  what the editor itself would have stored, so a document seeded some other
  way is rewritten canonically once and then left alone.

Two things to know: two nodes sharing one id are matched by their last
occurrence, and a short paragraph rewritten almost entirely (fewer than half
its words kept) is treated as removed and re-added, so the other editor's
change to it shows up as a block conflict rather than a word merge. Undo stays
off for a `RichEditor` with an attachment provider, like any file operation.

The browser sends the document it started from with each save (a rich field
has no compact patch), which costs a little more than a text patch on a very
long document; on the server a rich merge adds exactly one query, the read of
the rich columns before the form dehydrates.

#### In the browser

Nothing to install or build. When a page lists merge fields, the indicator
loads one small script of the package's own (about 24 KB, no dependencies,
through Livewire's `@assets`, so it loads once per page and never travels
inside a Livewire response). It speaks the same word-level diff and patch
format as the server, and the controller takes it from there.

- For each mergeable field it remembers the last value the server
  acknowledged (on load, after each save, after each refill or merge) and
  sends only the *difference* from it with every autosave. The base itself is
  never sent, so a very long textarea costs no more than the words that
  changed.
- Whatever comes back, the merged text after a save or the other editor's
  current value from a poll while you still have unsaved edits, is merged
  **inside the input**, around what you're typing. The caret and selection
  stay on the words they were on, and text typed while the request was in
  flight is kept. The badge shows `synced` for a poll and `saved` for a save,
  as usual.
- If both of you changed the same words, the later save keeps its words and
  the other editor's version shows up in a callout under the indicator, with
  a link to put it back. It replaces your words if they're still there,
  otherwise it's inserted at that spot. The callout stays until you recover
  or dismiss it; recovering is an ordinary edit and gets saved like one.
- If a field couldn't be written after every retry, the browser adopts the
  merge computed against the latest value, takes that as its new base and
  lets the next autosave retry from there. The field stays dirty and the
  callout says why. Nothing you typed is lost at any point.

The caret handling applies to `TextInput`, `Textarea` and `RichEditor`. A
merged `MarkdownEditor` value is set on the state and the editor re-renders
it (the merge is kept, the caret is not).

A `RichEditor` gets the same treatment on its document, through a second
small script of ours (about 13 KB). It borrows ProseMirror from the editor
Filament already put on the page (nothing else is loaded), keeps the editor's own JSON as the base, sends that document with
the save, and applies whatever comes back as one editor transaction that
touches only the blocks that changed: the caret and selection are mapped
through it, and text typed while the save was in flight is merged back in,
block by block. Filament's usual "reset the editor on a server refill" is
skipped for that one update, which is what keeps the caret. A conflict shows
the other version as text in the callout; recovering puts the other editor's
nodes back at their block (in place of yours when they are still there),
images and custom blocks included, as an ordinary edit.

<details>
<summary>Payload contract (version 1)</summary>

The `autosave-status` event carries `v: 1` and, on `saved`/`validation`:

- `merged`: `{path: value}` for mergeable fields whose merged value differs
  from what the browser sent (the stored value, or, when contended, the merge
  computed against the latest value so the browser can adopt it);
- `conflicts`: `{path: [{ours, theirs, position, reason}]}`, where `reason` is
  `overlap` (resolved last-write-wins in that range; `position` is the
  code-point offset in the merged value) or `contended` (left unwritten);
- `patches`: `{path: {theirs, hash}}` for contended fields, the latest value
  and its xxh128 for the browser to rebase on.

For a `RichEditor` every value is the Tiptap document the form holds,
whatever the column stores: `merged[path]` and `patches[path].theirs` are
documents, and a conflict's `ours`/`theirs` are lists of nodes, with `kind`
(`inline` or `block`), `block` (the path of child indexes to the block in the
merged document; for a deleted block, where it would go back) and `position`
(a code-point offset into the document's plain text, blocks joined by
newlines). `hash` is always the xxh128 of the raw column value.

On `synced`: `refreshed`, `stale`, `patches` (same shape, for stale mergeable
fields) and an always-empty `conflicts`.

Parameters: `autosave(['title' => '<patch text>'])` or
`autosave(['title' => ['base' => '…', 'ours' => '…']])` for plain text,
`autosave(['body' => ['base' => <document>]])` for a `RichEditor` (the current
value is taken from the form; a patch string sent for a rich field is
ignored); `syncAutosave(['title' => '<xxh128 of the value the browser
holds>'])`. `AutosaveSaved` gains `merged` and `conflicts`, `AutosaveSynced`
gains `patches`, `AutosaveConflict` gains `conflicts`. A save that arrives
without a patch (an older tab, or a field not listed) behaves exactly as
before.

</details>

## Undo

For five seconds after a successful Edit save, the user can undo it. The
snapshot itself lives for 90 minutes by default; change that with
`getUndoTtlMinutes()` or `undo_ttl`.

Undo restores the previous values of:

- model columns, including `BelongsTo` and `MorphTo` keys;
- `BelongsToMany` pivot data;
- `HasOne` and `HasMany` child records;
- supported `HasManyThrough` graphs, including rows that have to be restored,
  updated, or removed.

Before restoring anything, Undo checks that the current value is still the one
the autosave wrote. If another user changed that same value in the meantime,
Undo backs off with a conflict status so their change survives. Changes to
other columns don't get in the way.

Undo runs the relevant Filament save hooks and events and shows the normal
saved notification. It's tied to the live page instance that made the save.

File operations and RichEditor attachments keep Undo disabled by default 
because a database transaction can't roll back the filesystem or an external
store. If your provider can be snapshot, compare, and restore its state, register a
reversible `AutosaveExternalUndoAdapter` in `external_undo_adapters` (it
implements `supports`, `snapshot`, `matches` and `restore`). If any changed
external field has no adapter, Undo stays off for the whole cycle rather than
restoring half of it.

<details>
<summary>Limits</summary>

Nested relationship snapshots go as deep as `relationship_undo_depth` (eight
levels by default). If a pending graph is deeper than that, Undo is disabled
for that cycle instead of restoring only part of the graph.

</details>

## Uploads and media

Edit pages get upload support through `HasAutosave`; there's no need to add
`HasAutosaveUploads` yourself.

### `FileUpload`

`FileUpload` handles new files, removals, and reordering. Upload validation runs
before anything is stored permanently, including size and type rules. An
invalid upload leaves its whole column alone, while the rest of the form still
saves.

Skipped:

- `storeFiles(false)` uploads;
- disabled or hidden upload fields;
- excluded fields;
- half-filled nested containers.

Removing a path from a normal `FileUpload` updates the column; whether the file
is physically deleted follows the component's own configuration.

### Spatie Media Library

`SpatieMediaLibraryFileUpload` uses its relationship callback for additions,
removals, and ordering. Collections that didn't change aren't touched. Install
Filament's Spatie plugin in your app to use this; for this package it's only a
development dependency.

Upload fields inside a `Repeater` bound to a relationship are saved together
with that relationship, on Edit pages and record-backed generic forms. Media in
an existing row is attached to that row's record; media in a new row is
attached once the relationship component has created the row. If any field in
the repeater fails validation, the whole relationship write is skipped and no
file is stored.

### Media inside a JSON repeater

A `Repeater` stored in a JSON column has no record per row, so every
`SpatieMediaLibraryFileUpload` inside it hangs off the parent record. If the
rows share one collection, saving one row's component would delete the files
the other rows own (that's Filament's `deleteAbandonedFiles()`). So that case
stays blocked: the repeater column shows up as `pending` in the status event
and nothing is written until you save explicitly.

Give each row its own collection and the rows autosave independently:

```php
Repeater::make('settings')->schema([
    Hidden::make('uuid')->default(fn (): string => (string) Str::uuid()),
    TextInput::make('label'),
    SpatieMediaLibraryFileUpload::make('images')
        ->multiple()
        ->collection(fn (Get $get): string => 'row_'.$get('uuid')),
]),
```

The rule is exact: the media field is autosaved when every row resolves a
non-empty collection other than `default`, all those collections are
different, and none of them is also used by a top-level media field of the
same record. Anything else is blocked and reported as pending. The `Hidden`
uuid has to be stored in the row JSON, so a reordered row keeps its collection
and a new row gets a fresh one.

Cleanup is yours: deleting a row doesn't delete its collection. Remove the
orphaned media yourself, for example in a model observer that compares the
stored uuids with the record's collections.

### Failure cleanup, ledger and transactions

Auto saves that involve files keep Undo disabled by default. A registered
external adapter can make a provider reversible; without one, a later
validation, hook, relationship or database failure still cleans up the new
paths and tracked media where the provider allows it. Side effects of a custom
storage callback are the app's responsibility.

Newly stored paths are also written to a short-lived cleanup ledger. Register
the pruning command in your scheduler so a PHP process that dies mid-save
can't leave those paths behind forever:

```php
$schedule->command('filament-autosave:prune-uploads')->everyThirtyMinutes();
```

Every autosave write runs inside a database transaction: Filament's own when
the panel has `databaseTransactions()` enabled, the package's own otherwise.

<details>
<summary>How staging, the ledger, and the transaction fit together</summary>

The controller waits for in-flight uploads to finish, and its request-end hash
also catches server-side actions such as removing a row or reordering files.
Livewire's temporary upload is the staging area until validation passes.
Permanent paths are tracked until the owning transaction has run its
`afterCommit` callbacks, so a rollback can remove every path the cycle
created.

The ledger is a safety net for storage providers; a database and a filesystem
still can't commit as one distributed transaction. A Spatie Media Library file
is journaled the moment its `media` row is created, through a `created`
listener the package registers at boot. Spatie saves the row before copying
the file, so the ledger entry exists before the file reaches disk, and a
process killed at any later point still leaves a trail for pruning. The only
gap left is the row insert itself, which the surrounding transaction covers.

</details>

## Explicit saves with `flushAutosave()`

`autosave()` is built for the background loop: it reports failures through the
indicator and never throws. Explicit actions, a "Generate slug" button, a
"Publish" toggle, an "Apply template" modal, usually want the opposite. The
same dirty-only write, refresh and Undo, but with errors reaching the caller
so the action can report them.

```php
Action::make('generateSlug')
    ->action(function (): void {
        $this->data['slug'] = Str::slug($this->data['title']);

        $written = $this->flushAutosave();
    });
```

`flushAutosave()` runs one cycle synchronously and returns whether anything
was written. Validation errors stop the cycle before any write and are thrown
as a `ValidationException` keyed by state path (`data.title`), so Filament
shows them inline. Exceptions from `beforeAutosave()`, custom rules, hooks or
persistence propagate untouched, and so does Filament's `Halt`, so the
surrounding action can stop cleanly. It's available on every autosave trait.

## Events

Every trait dispatches plain Laravel events, so you can observe autosave
without touching the indicator. They're dispatched as objects, so type-hinted
listeners work. `record` is `null` for drafts and Create pages.

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

## Extension points and stability

Only the members tagged `@api` in the traits are stable extension points. A
test (`tests/Unit/ExtensionContractTest.php`) pins that list, so it only
changes on purpose and with a changelog entry. Everything else in the traits is
internal and may be renamed or reshaped in a minor release, even when it's
`protected`. Overriding an internal method works today, but it isn't
supported.

| Override (`protected`) | Purpose |
| --- | --- |
| `shouldAutosave()` | Turn autosave on or off for this component |
| `autosaveDebounce()` / `autosaveExcept()` | Per-page debounce and excluded fields |
| `autosavePollInterval()` | Per-page poll interval for other editors' changes; `0` disables |
| `autosaveMergeFields()` | Per-page plain-text fields merged word by word; `null` uses the plugin/config |
| `beforeAutosave(array $data): array` | Inspect or change the eligible state before validation |
| `getAutosaveValidationRules()` | Extra rules; failing fields are skipped |
| `afterAutosave(object $record)` | Work after each successful Edit-page save |
| `getUndoTtlMinutes()` | Undo snapshot lifetime |
| `resolveAutosaveForm()` / `getAutosaveStatePath()` | Which schema and state path autosave uses |
| `persistAutosaveForm(array $data)` | Custom persistence for generic forms |
| `getAutosaveFormContext()` | Draft/Undo scope for generic forms |

| Call (`public`) | Purpose |
| --- | --- |
| `autosave(array $mergePatches = [])` | Background save; never throws. Patches per mergeable field |
| `flushAutosave(): bool` | Synchronous save that throws |
| `undoAutosave()` | Restore the last autosave |
| `syncAutosave(array $mergeBaseHashes = [])` | Pull other editors' changes into untouched fields (what the poll calls) |
| `getAutosavePollInterval()` / `getAutosaveMergeFields()` | Resolved poll interval and mergeable fields |
| `restoreDraft()` / `discardDraft()` / `clearAutosaveDraft()` | Draft lifecycle |
| `isAutosaveEnabled()` / `getAutosaveDebounce()` / `getAutosaveExcept()` | Resolved settings |

The package's own events (`Lenorix\FilamentAutosave\Events\*`) are the
supported way to watch the lifecycle without overriding anything.

## Configuration

Settings are resolved in this order: config, then plugin, then page. The last
one wins, except `except`, whose entries are merged across all three.

| Option | Config | Plugin | Page |
| --- | :---: | :---: | :---: |
| `debounce` (milliseconds) | Yes | Yes | Yes |
| `except` (field names) | Yes | Yes | Yes |
| `draft_ttl` (hours) | Yes | Yes | No |
| `undo_ttl` (minutes) | Yes | Yes | No |
| `dirty_only` | Yes | No | No |
| `refresh_unchanged_fields` | Yes | No | No |
| `poll_interval` (milliseconds, 0 = off) | Yes | Yes | Yes |
| `merge_fields` (plain-text field names) | Yes | Yes | Yes |
| `merge_retries` (conditional writes per field) | Yes | No | No |
| `require_form_context` | Yes | No | No |
| `relationship_undo_depth` | Yes | No | No |
| `external_undo_adapters` | Yes | No | No |
| `upload_ledger_ttl` (minutes) | Yes | No | No |
| `show_saved_at` | Yes | Yes | No |
| `position` | Yes | Yes | No |
| `exceptPages` | No | Yes | No |

```php
// config/filament-autosave.php
'debounce' => 750,
'except' => ['password', 'password_confirmation'],
'draft_ttl' => 72,
'undo_ttl' => 90,
'dirty_only' => true,
'refresh_unchanged_fields' => true,
'require_form_context' => false,
'relationship_undo_depth' => 8,
'external_undo_adapters' => [],
'upload_ledger_ttl' => 180,
```

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

On a page, settings are methods. Don't redeclare properties that a trait
already supplies: conflicting trait properties are a PHP fatal error.

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

`shouldAutosave()` is evaluated on the server; the browser can't change it.

## Translations

```bash
php artisan vendor:publish --tag="filament-autosave-translations"
```

English and Spanish ship with the package, and a test keeps every shipped
locale in key parity with English. The labels are `unsaved`, `saving`,
`saved`, `saved_at`, `undo`, `undone`, `conflict`, `error`, `draft_available`,
`restore`, `discard`, `restored`, `synced` and `stale`. Validation messages use
the `validation` key, and the indicator lists the `pending` field paths.

## The indicator

The status indicator is built only from Filament's own components (badge,
link, callout), so it follows the panel's light and dark themes and typography
with no stylesheet or Tailwind build of its own.

- **Position**: `before` or `after` the page header (`position` /
  `indicatorPosition()`). On a page without a usable header it goes at the
  end of the page.
- **Timestamp**: `show_saved_at` / `showTimestamp()` toggles the "Stored at"
  time on the saved badge.
- **Pending fields**: after a save that skipped something, the indicator lists
  the skipped field paths: validation failures, blank required values, invalid
  uploads, or half-filled containers.
- **Stale fields**: after a poll, the fields that are dirty locally and changed
  remotely are listed, without touching the local value.
- **Undo**: shown during the Undo window whenever `autosaveCanUndo` is true,
  on Edit pages and record-backed generic forms alike.

Generic components include it themselves with
`@include('filament-autosave::autosave-indicator', ['mode' => 'form'])`; see
[Any other form](#any-other-form).

## Testing

```bash
composer test
vendor/bin/pest --testsuite=Unit
vendor/bin/pest --testsuite=Integration
```

CI runs the suite on PHP 8.4 and 8.5, Filament `^4.0` and `^5.0`, and Laravel
12 and 13 (Testbench `^10.0` and `^11.0`), plus one prefer-lowest job.

`tests/Browser` drives the real panel in Chromium through Pest's browser
plugin and is left out of `composer test`. It needs Node only for the
Playwright browser driver (`npm ci && npx playwright install chromium`), then:

```bash
composer test:browser
```

## License

[The Unlicense](LICENSE.md)
