# Changelog

## Unreleased

- Keep per-field hashes across Livewire requests for `dirty_only`, without storing
  original form values. Reset after successful explicit saves and Undo; retain
  pending fields when validation or persistence skips them.
- Detect server-side form changes and defer autosave during active uploads.
- Autosave validated column-backed file uploads and top-level Spatie Media Library
  additions, removals and ordering. Only changed upload fields are processed.
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
