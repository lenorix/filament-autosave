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

    // Milliseconds between polls that pull other editors' changes into the
    // fields this user is not editing. 0 disables polling. Requires
    // refresh_unchanged_fields; the poll never overwrites a dirty field.
    'poll_interval' => 5000,

    // Top-level text fields whose concurrent edits are merged instead of
    // last-write-wins: TextInput, Textarea and MarkdownEditor word by word
    // (the browser sends a patch of its own change; the server plays it on
    // the current value), RichEditor block by block over its document (the
    // browser sends the document it started from; images, custom blocks and
    // other atomic nodes are never split). Other listed field types are
    // ignored with a warning.
    'merge_fields' => [],

    // Conditional writes a merged field retries when another editor commits
    // to that same column between the read and the write. Each retry re-reads
    // the column, merges again and waits a few milliseconds more than the
    // last (5 ms doubling up to 100 ms; ten retries wait 655 ms in total).
    // Running out means sustained contention on one field: the column is
    // left unwritten and reported, the user's text stays in the form and the
    // next cycle retries on its own.
    'merge_retries' => 10,

    // Generic forms may require an explicit owner/record/action context to
    // prevent two instances of the same component sharing a draft.
    'require_form_context' => false,

    // Maximum number of nested relationship components captured by Undo.
    'relationship_undo_depth' => 8,

    // External storage adapters may opt a field into reversible Undo.
    'external_undo_adapters' => [],

    // How long interrupted upload entries stay available to the pruning job.
    'upload_ledger_ttl' => 180,

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
