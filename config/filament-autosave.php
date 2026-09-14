<?php

return [
    // Delay before saving, in milliseconds.
    'debounce' => 750,

    // Undo snapshot lifetime, in minutes.
    'undo_ttl' => 90,

    // Draft lifetime, in hours.
    'draft_ttl' => 72,

    // Save only fields changed since the edit page was loaded.
    'dirty_only' => true,

    // After a successful dirty-only save, refresh clean top-level model fields
    // from the database while preserving local edits. Requires dirty_only.
    'refresh_unchanged_fields' => true,

    // Generic forms may require an explicit owner/record/action context to
    // prevent two instances of the same component sharing a draft.
    'require_form_context' => false,

    // Maximum number of nested relationship components captured by Undo.
    'relationship_undo_depth' => 8,

    // External storage adapters may opt a field into reversible Undo.
    'external_undo_adapters' => [],

    // Password inputs are always excluded.
    'except' => [
        'current_password',
        'new_password',
        'new_password_confirmation',
        'password',
        'password_confirmation',
    ],

    // Show the time of the last successful autosave in the status indicator.
    'show_saved_at' => true,

    // Indicator placement in the page header.
    'position' => 'before',
];
