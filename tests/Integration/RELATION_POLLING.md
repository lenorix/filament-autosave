# Deep relationship polling: implementation decision

Status: implemented for rendered relationship components, with bounded depth
and row-cost controls.

Current coverage: top-level and rendered nested relationships refresh without a
parent update; relations without timestamps use persisted row/pivot hashes,
including stale reporting for dirty local fields. Depth and row limits are
configurable to keep polling bounded. Builder/Repeater siblings, remote
parents, morphMany, through fingerprints, self-referential cycles, UUID keys,
and query budgets are covered by integration fixtures.

## Proposed contract

Independent fingerprints are created for nested relationships declared in the
form schema. Traversal is bounded by `poll_relationship_depth`; it does not
reuse Undo depth or discover arbitrary Eloquent relationships.

Identify a node by schema path, parent model class/key, relation name and child
key (plus morph type or pivot identity where applicable). Never use transient
Repeater UUIDs or row positions as database identities. A remote insertion,
deletion, reorder or pivot change must affect the enclosing field fingerprint.
Hash persisted attributes for timestamp-free nodes up to
`poll_relationship_max_rows`. Larger relations use key/count aggregates;
timestamp columns are recommended when exact edits must be detected at scale.

Load by relationship path and parent key sets, not one query per rendered row.
The implementation batches clean rendered parents at each relationship level,
respects relation scopes and exclusions, and never recursively discovers an
arbitrary Eloquent graph; rendered cycles therefore remain bounded. Evaluate
query/row volume for polymorphic and through relations explicitly.
A new remote row must be discoverable even if the local schema has no instance
for it yet. Never hydrate the active form to calculate remote fingerprints.

Refresh the enclosing clean field through Filament. If any descendant is dirty,
retain the entire local field and mark it stale. Advance the acknowledged
fingerprint only after successful refill; repeated polls must retain stale
warnings. Polling must not persist anything or modify Undo snapshots.

## Required regression coverage before enabling

- Edit pages and record-backed generic forms with two or more rows at each level.
- Child edits/additions/deletions/reorders without `$touches` or parent timestamps.
- Mixed Builder/Repeater schemas, empty relations, morphs, pivots and through paths.
- Remote insertion under a newly added parent absent from the local schema.
- Dirty deep sibling preservation and persistent stale state across repeated polls.
- Failed refill retries, exclusions, depth limits, cycles, and query budgets.
- Browser polls racing with typing, autosave and Undo.

## Other boundaries

Filament `RecordSaved`/`RecordUpdated` require a Page host; use package events in
Relation Managers and generic components rather than fabricating a Page.
Mounted action form-validation and `before`/`after` hooks run during autosave. The action's submit callback remains
submit-only; success notifications and redirects run only after an explicit `flushAutosave()` commits.
External Undo continues to require reversible adapters; generic file rollback
cannot restore deleted external content. Nested merge needs stable row identity
and deletion/reorder semantics before expanding beyond top-level text fields.
