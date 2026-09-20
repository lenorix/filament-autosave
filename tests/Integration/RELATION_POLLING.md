# Deep relationship polling: implementation decision

Status: proposed follow-up; not implemented by the current polling code.

Current coverage: top-level relationships refresh without a parent update;
relations without timestamps use persisted row/pivot hashes, including stale
reporting for dirty local fields. Deep child changes still require `$touches`.

## Proposed contract

Support independent fingerprints for nested relationships declared in the form
schema. Make deeper traversal opt-in with a separate bounded polling depth;
do not reuse Undo depth or discover every Eloquent relationship on a model.
Keep the existing default query cost for applications that do not opt in.

Identify a node by schema path, parent model class/key, relation name and child
key (plus morph type or pivot identity where applicable). Never use transient
Repeater UUIDs or row positions as database identities. A remote insertion,
deletion, reorder or pivot change must affect the enclosing field fingerprint.
Hash persisted attributes for timestamp-free nodes. Timestamp aggregates alone
cannot reliably detect same-second updates, unchanged maxima or changed pivot
values; an exact mode needs content hashes or explicit revision counters.

Load by relationship path and parent key sets, not one query per rendered row.
Bound traversal depth, reject cycles, respect relation scopes and exclusions,
and evaluate query/row volume for polymorphic and through relations explicitly.
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
Action submission hooks/notifications remain distinct from background autosave.
External Undo continues to require reversible adapters; generic file rollback
cannot restore deleted external content. Nested merge needs stable row identity
and deletion/reorder semantics before expanding beyond top-level text fields.
