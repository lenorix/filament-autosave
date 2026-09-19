<?php

namespace Lenorix\FilamentAutosave;

/**
 * Outcome of a structural three-way merge of rich content.
 *
 * `value` is canonical, in the column's format (HTML string or ProseMirror
 * document array). Each conflict is a range both sides changed differently,
 * resolved in favour of `ours`:
 *
 * - `kind`: `inline` (words inside one text block) or `block` (a whole node);
 * - `ours` / `theirs`: the two versions as fragments in the column format —
 *   for `inline`, a single block of the conflicting block's type holding the
 *   range; for `block`, the node itself — an empty document when that side
 *   deleted the range;
 * - `block`: path of child indexes from the document root to the block in
 *   `value` (for a deleted block, the index where it would be re-inserted);
 * - `position`: code-point offset in `plainText(value)` where the range
 *   starts;
 * - `reason`: always `overlap`.
 *
 * @internal
 */
final class AutosaveRichMergeResult
{
    /**
     * @param  string|array<string, mixed>  $value
     * @param  list<array{kind: string, ours: string|list<array<string, mixed>>, theirs: string|list<array<string, mixed>>, reason: string, block: list<int>, position: int}>  $conflicts
     */
    public function __construct(
        public readonly string|array $value,
        public readonly array $conflicts,
        public readonly bool $changed,
    ) {}
}
