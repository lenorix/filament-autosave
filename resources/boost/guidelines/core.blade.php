# Filament Autosave coding guidelines

Filament Autosave is a package for applications that use Filament forms. Keep
application code focused on the public traits and hooks below; do not depend
on internal trait methods or properties.

## Choose the host trait

- `HasAutosave` for Filament `EditRecord` pages.
- `HasAutosaveForCreate` for `CreateRecord` pages and custom draft forms.
- `HasAutosaveForRelationManager` for relation-manager action modals.
- `HasAutosaveForForm` for actions, modals, table forms, and standalone
  Livewire components.

Generic forms must call `mountHasAutosaveForForm()` from `mount()`, render the
`filament-autosave::autosave-indicator` view, and return a stable value from
`getAutosaveFormContext()`. Relation managers provide their own context and
indicator wiring.

## Public customization points

Use these methods when an application needs custom behavior:

- `shouldAutosave()` and `autosaveDebounce()` control whether and when saves run.
- `autosaveExcept()` excludes fields for one page or component.
- `autosavePollInterval()` and `autosaveMergeFields()` configure polling and text merging.
- `beforeAutosave()` changes eligible data before validation.
- `getAutosaveValidationRules()` adds autosave-specific rules.
- `afterAutosave()` runs after a successful Edit-page save.
- `resolveAutosaveForm()` and `getAutosaveStatePath()` select a generic form.
- `persistAutosaveForm()` supplies persistence for a custom generic-form lifecycle.
- `getAutosaveFormContext()` isolates drafts and Undo snapshots.

Use package events under `Lenorix\FilamentAutosave\Events` to observe saves,
failures, synchronization, conflicts, and Undo. Do not override other
protected methods unless the package explicitly documents them as public API.

## Persistence rules

Record-backed Edit and Create cycles run authorization, Filament validation,
lifecycle hooks, and the record save flow inside one database transaction.
Generic forms use the host transaction when one is available. Invalid fields
are skipped; valid sibling fields can still be written. A group, builder, or
other single-column container is written only when all of its children are
valid.

With `dirty_only` enabled, write only fields that changed since the last
acknowledged save. Keep top-level field paths stable so hashes, refresh, and
Undo can identify them correctly. Do not manually mutate the hash or snapshot
properties.

`autosave()` is the background entry point and reports failures through the
indicator. Use `flushAutosave()` when an action needs a synchronous result or
must receive thrown validation and persistence exceptions.

## Relationships and uploads

Use Filament relationship components and let the package run their normal
relationship callbacks. Nested relationship repeaters are supported. Do not
share a Spatie Media Library collection between rows of a JSON repeater; give
each row a stable UUID-based collection name.

Uploads are staged and cleaned up when a save fails. Files and media do not
participate in Undo unless a reversible `AutosaveExternalUndoAdapter` is
configured. Keep the upload pruning command scheduled in consuming
applications.

## Concurrency

Different fields use dirty-only writes so editors do not overwrite each
other. The same field uses last-write-wins unless it is listed in
`mergeFields`. Polling refreshes untouched fields and marks locally dirty
fields as `stale`; it must never replace an active local edit.

When changing concurrency behavior, cover at least two Livewire instances,
one untouched field, one same-field conflict, Undo after a remote update, and
relationship or upload state where applicable.

## Verification

Run the PHP suite and formatter for changes to package behavior:

~~~bash
composer test
vendor/bin/pint --test
~~~

Run `composer test:browser` for changes to the indicator, polling, merging, or
other browser behavior. Pest remains the test runner; Playwright only supplies
the browser driver.
