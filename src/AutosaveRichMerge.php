<?php

namespace Lenorix\FilamentAutosave;

use Tiptap\Editor;

/**
 * Structural three-way merge of RichEditor content.
 *
 * Both column formats (HTML and Tiptap JSON) are parsed to the ProseMirror
 * document Tiptap PHP produces, merged as trees, and serialised back to the
 * column format, so the result is always canonical — exactly what the editor
 * itself would have stored. The injected editor carries the RichEditor's own
 * extensions, so custom blocks and plugins are parsed with their schema.
 *
 * Algorithm (what the client side must mirror):
 *
 * 1. Block level. Every node list (document children, list items, blockquote
 *    or details content, grid columns…) is aligned base↔ours and base↔theirs:
 *    nodes carrying `attrs.id` (image, customBlock, mention, mergeTag) match
 *    by `type#id` wherever they are — a moved node is a move, not a
 *    delete-plus-insert; other nodes match in order by content similarity
 *    (same type, or both text blocks, with ≥ 50 % of their words in common,
 *    or byte-equal), never by index alone. Insertions from both sides at the
 *    same point are both kept, ours first. A block one side deleted and the
 *    other left untouched is deleted; deleted on one side and changed on the
 *    other is an overlap.
 * 2. Node level. A matched node is compared after projection (image `src`
 *    is ignored when the image has an `id`, since the state cast nulls it
 *    for private attachments; custom block `label`/`preview` are ignored).
 *    Unchanged on one side ⇒ the other side's version. Changed on both:
 *    atomic nodes (image, customBlock, horizontalRule, or any node without
 *    content) are an overlap, ours wins; containers recurse into step 1;
 *    text blocks go to step 3. A type or attribute change of a non-atomic
 *    node (paragraph → heading) is taken from the side that changed it.
 * 3. Inline level. A text block's content is tokenised into words, runs of
 *    whitespace and single punctuation characters, each carrying its marks,
 *    plus one opaque token per inline node (hardBreak, mention, mergeTag);
 *    `codeBlock` text is one token per text node. The three token lists are
 *    merged with the same diff3 rule as `AutosaveTextMerge`: hunks touching
 *    different base tokens are both applied, overlapping ones keep ours and
 *    report theirs. A mark-only change and a text change on the same words
 *    overlap. Adjacent tokens with equal marks are joined back into one text
 *    node.
 *
 * Positions in conflicts are code-point offsets into `plainText()`: leaf
 * blocks in document order, text nodes concatenated, `hardBreak` as "\n",
 * other inline nodes as "", blocks joined by "\n".
 *
 * @internal
 */
final class AutosaveRichMerge
{
    private const ID_TYPES = ['image', 'customBlock', 'mention', 'mergeTag'];

    private const BLOCK_ATOMS = ['image', 'customBlock', 'horizontalRule'];

    private const INLINE_TYPES = ['text', 'hardBreak', 'mention', 'mergeTag'];

    private const TEXTBLOCK_TYPES = ['paragraph', 'heading', 'codeBlock', 'detailsSummary'];

    private const WHOLE_TEXT_TYPES = ['codeBlock'];

    private const SIMILARITY = 0.5;

    /** @var list<array<string, mixed>> */
    private array $conflicts = [];

    public function __construct(
        private readonly Editor $editor,
        private readonly bool $json = false,
    ) {}

    // ---------------------------------------------------------------------
    // Public surface
    // ---------------------------------------------------------------------

    /**
     * Combine two edits of `$base`. Overlapping changes keep `$ours`.
     *
     * @param  string|array<string, mixed>|null  $base
     * @param  string|array<string, mixed>|null  $ours
     * @param  string|array<string, mixed>|null  $theirs
     */
    public function merge(string|array|null $base, string|array|null $ours, string|array|null $theirs): AutosaveRichMergeResult
    {
        $baseDoc = $this->doc($base);
        $oursDoc = $this->doc($ours);
        $theirsDoc = $this->doc($theirs);
        $this->conflicts = [];

        if ($this->same($oursDoc, $theirsDoc)) {
            $merged = $this->restore($oursDoc, [$theirsDoc, $baseDoc]);
        } else {
            $merged = [
                'type' => 'doc',
                'content' => $this->mergeSequence($baseDoc['content'] ?? [], $oursDoc['content'] ?? [], $theirsDoc['content'] ?? [], []),
            ];
        }

        $conflicts = $this->resolveConflicts($merged);
        $merged = $this->stripMarkers($merged);

        return new AutosaveRichMergeResult($this->serialize($merged), $conflicts, ! $this->same($merged, $baseDoc));
    }

    /**
     * The value as the editor itself would store it.
     *
     * @param  string|array<string, mixed>|null  $value
     * @return string|array<string, mixed>
     */
    public function canonical(string|array|null $value): string|array
    {
        return $this->serialize($this->doc($value));
    }

    /**
     * @param  string|array<string, mixed>|null  $value
     */
    public function isCanonical(string|array|null $value): bool
    {
        $canonical = $this->canonical($value);

        return is_array($canonical) ? $canonical === $value : $canonical === $value;
    }

    /**
     * Ids of the image attachments a value references, in document order.
     *
     * @param  string|array<string, mixed>|null  $value
     * @return list<string>
     */
    public function attachmentIds(string|array|null $value): array
    {
        $ids = [];

        $this->walk($this->doc($value), static function (array $node) use (&$ids): void {
            $id = $node['attrs']['id'] ?? null;

            if ($node['type'] === 'image' && is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        });

        return $ids;
    }

    /**
     * The text `position` offsets in conflicts count into.
     *
     * @param  string|array<string, mixed>|null  $value
     */
    public function plainText(string|array|null $value): string
    {
        return implode("\n", $this->blockTexts($this->doc($value)));
    }

    // ---------------------------------------------------------------------
    // Block level
    // ---------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $base
     * @param  list<array<string, mixed>>  $ours
     * @param  list<array<string, mixed>>  $theirs
     * @param  list<int>  $path
     * @return list<array<string, mixed>>
     */
    private function mergeSequence(array $base, array $ours, array $theirs, array $path): array
    {
        $o = $this->align($base, $ours);
        $t = $this->align($base, $theirs);
        $output = [];
        $count = count($base);

        for ($k = -1; $k < $count; $k++) {
            foreach (['ours' => $o, 'theirs' => $t] as $side => $alignment) {
                foreach ($alignment['inserts'][$k] ?? [] as $insert) {
                    $node = $this->insertedNode($side, $insert, $base, $o, $t, $path, count($output));

                    if ($node !== null) {
                        $output[] = $node;
                    }
                }
            }

            $i = $k + 1;

            if ($i >= $count) {
                break;
            }

            $node = $this->baseNode($i, $base, $o, $t, $path, count($output));

            if ($node !== null) {
                $output[] = $node;
            }
        }

        return $output;
    }

    /**
     * A node one side inserted or moved to this point.
     *
     * @param  array{node: array<string, mixed>, from: int|null}  $insert
     * @param  list<array<string, mixed>>  $base
     * @param  array{state: array<int, string>, node: array<int, array<string, mixed>>, inserts: array<int, list<array{node: array<string, mixed>, from: int|null}>>}  $o
     * @param  array{state: array<int, string>, node: array<int, array<string, mixed>>, inserts: array<int, list<array{node: array<string, mixed>, from: int|null}>>}  $t
     * @param  list<int>  $path
     * @return array<string, mixed>|null
     */
    private function insertedNode(string $side, array $insert, array $base, array $o, array $t, array $path, int $index): ?array
    {
        $from = $insert['from'];

        if ($from === null) {
            return $insert['node'];
        }

        $other = $side === 'ours' ? $t : $o;
        $otherState = $other['state'][$from];

        // Both moved it: it lives where ours put it.
        if ($side === 'theirs' && $o['state'][$from] === 'moved') {
            return null;
        }

        if ($otherState === 'deleted') {
            if ($side === 'ours') {
                $this->blockConflict($path, $index, $insert['node'], null);

                return $insert['node'];
            }

            $this->blockConflict($path, $index, null, $insert['node']);

            return null;
        }

        $ours = $side === 'ours' ? $insert['node'] : $o['node'][$from];
        $theirs = $side === 'theirs' ? $insert['node'] : $t['node'][$from];

        return $this->mergeNode($base[$from], $ours, $theirs, [...$path, $index]);
    }

    /**
     * The base node at `$i`, as both sides left it.
     *
     * @param  list<array<string, mixed>>  $base
     * @param  array{state: array<int, string>, node: array<int, array<string, mixed>>, inserts: array<int, list<array{node: array<string, mixed>, from: int|null}>>}  $o
     * @param  array{state: array<int, string>, node: array<int, array<string, mixed>>, inserts: array<int, list<array{node: array<string, mixed>, from: int|null}>>}  $t
     * @param  list<int>  $path
     * @return array<string, mixed>|null
     */
    private function baseNode(int $i, array $base, array $o, array $t, array $path, int $index): ?array
    {
        $os = $o['state'][$i];
        $ts = $t['state'][$i];

        if ($os === 'moved' || $ts === 'moved') {
            return null;
        }

        if ($os === 'kept' && $ts === 'kept') {
            return $this->mergeNode($base[$i], $o['node'][$i], $t['node'][$i], [...$path, $index]);
        }

        if ($os === 'deleted' && $ts === 'deleted') {
            return null;
        }

        if ($os === 'deleted') {
            if (! $this->same($base[$i], $t['node'][$i])) {
                $this->blockConflict($path, $index, null, $t['node'][$i]);
            }

            return null;
        }

        if ($this->same($base[$i], $o['node'][$i])) {
            return null;
        }

        $this->blockConflict($path, $index, $o['node'][$i], null);

        return $o['node'][$i];
    }

    /**
     * How `$side` relates to `$base`: per base index `kept`, `deleted` or
     * `moved`, that side's version of kept/moved nodes, and the nodes it
     * inserted or moved, anchored after a base index (-1 = at the start).
     *
     * @param  list<array<string, mixed>>  $base
     * @param  list<array<string, mixed>>  $side
     * @return array{state: array<int, string>, node: array<int, array<string, mixed>>, inserts: array<int, list<array{node: array<string, mixed>, from: int|null}>>}
     */
    private function align(array $base, array $side): array
    {
        // Nodes with a stable id are cheap to report as moved, so the in-order
        // match prefers id-less blocks when both cannot be kept in place.
        $pairs = $this->lcs($base, $side, fn (array $a, array $b): float => match (true) {
            ! $this->similar($a, $b) => 0.0,
            $this->idKey($a) !== null => 0.5,
            default => 1.0,
        });
        $baseToSide = [];
        $sideToBase = [];

        foreach ($pairs as [$i, $j]) {
            $baseToSide[$i] = $j;
            $sideToBase[$j] = $i;
        }

        // Nodes with a stable id that fell out of the in-order match moved.
        $moved = [];
        $unmatchedIds = [];

        foreach ($base as $i => $node) {
            if (! isset($baseToSide[$i]) && ($key = $this->idKey($node)) !== null) {
                $unmatchedIds[$key] = $i;
            }
        }

        foreach ($side as $j => $node) {
            $key = $this->idKey($node);

            if (! isset($sideToBase[$j]) && $key !== null && isset($unmatchedIds[$key])) {
                $moved[$j] = $unmatchedIds[$key];
                unset($unmatchedIds[$key]);
            }
        }

        // So did id-less blocks that still look like an unmatched base block.
        foreach ($side as $j => $node) {
            if (isset($sideToBase[$j]) || isset($moved[$j]) || $this->idKey($node) !== null) {
                continue;
            }

            foreach ($base as $i => $candidate) {
                if (! isset($baseToSide[$i]) && ! in_array($i, $moved, true) && $this->idKey($candidate) === null && $this->similar($candidate, $node)) {
                    $moved[$j] = $i;

                    break;
                }
            }
        }

        $state = [];
        $nodes = [];

        foreach ($base as $i => $node) {
            $state[$i] = isset($baseToSide[$i]) ? 'kept' : (in_array($i, $moved, true) ? 'moved' : 'deleted');

            if (isset($baseToSide[$i])) {
                $nodes[$i] = $side[$baseToSide[$i]];
            }
        }

        foreach ($moved as $j => $i) {
            $nodes[$i] = $side[$j];
        }

        $inserts = [];
        $anchor = -1;

        foreach ($side as $j => $node) {
            if (isset($sideToBase[$j])) {
                $anchor = $sideToBase[$j];

                continue;
            }

            $inserts[$anchor][] = ['node' => $node, 'from' => $moved[$j] ?? null];
        }

        return ['state' => $state, 'node' => $nodes, 'inserts' => $inserts];
    }

    /**
     * Three-way merge of one matched node.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $ours
     * @param  array<string, mixed>  $theirs
     * @param  list<int>  $path
     * @return array<string, mixed>
     */
    private function mergeNode(array $base, array $ours, array $theirs, array $path): array
    {
        if ($this->same($ours, $theirs)) {
            return $this->restore($ours, [$theirs, $base]);
        }

        if ($this->same($ours, $base)) {
            return $this->restore($theirs, [$ours, $base]);
        }

        if ($this->same($theirs, $base)) {
            return $this->restore($ours, [$theirs, $base]);
        }

        if ($this->isAtom($base) || $this->isAtom($ours) || $this->isAtom($theirs)) {
            $node = $this->restore($ours, [$theirs, $base]);
            $node['__conflicts'][] = ['kind' => 'block', 'ours' => $node, 'theirs' => $theirs, 'offset' => 0];

            return $node;
        }

        $shell = $this->same($this->shell($ours), $this->shell($base)) ? $this->shell($theirs) : $this->shell($ours);

        if ($this->isTextblock($base) && $this->isTextblock($ours) && $this->isTextblock($theirs)) {
            [$content, $conflicts] = $this->mergeInline($base, $ours, $theirs);
            $node = [...$shell, 'content' => $content];

            if ($conflicts !== []) {
                $node['__conflicts'] = array_map(fn (array $conflict): array => [
                    'kind' => 'inline',
                    'ours' => [...$shell, 'content' => $conflict['ours']],
                    'theirs' => [...$shell, 'content' => $conflict['theirs']],
                    'offset' => $conflict['offset'],
                ], $conflicts);
            }

            return $node;
        }

        if ($this->isTextblock($base) || $this->isTextblock($ours) || $this->isTextblock($theirs)) {
            $node = $this->restore($ours, [$theirs, $base]);
            $node['__conflicts'][] = ['kind' => 'block', 'ours' => $node, 'theirs' => $theirs, 'offset' => 0];

            return $node;
        }

        return [...$shell, 'content' => $this->mergeSequence($base['content'] ?? [], $ours['content'] ?? [], $theirs['content'] ?? [], $path)];
    }

    // ---------------------------------------------------------------------
    // Inline level
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $ours
     * @param  array<string, mixed>  $theirs
     * @return array{0: list<array<string, mixed>>, 1: list<array{ours: list<array<string, mixed>>, theirs: list<array<string, mixed>>, offset: int}>}
     */
    private function mergeInline(array $base, array $ours, array $theirs): array
    {
        $whole = in_array($base['type'] ?? null, self::WHOLE_TEXT_TYPES, true);
        $baseTokens = $this->inlineTokens($base['content'] ?? [], $whole);
        $oursTokens = $this->inlineTokens($ours['content'] ?? [], $whole);
        $theirsTokens = $this->inlineTokens($theirs['content'] ?? [], $whole);

        $hunks = [
            ...$this->hunks($baseTokens, $oursTokens, 'ours'),
            ...$this->hunks($baseTokens, $theirsTokens, 'theirs'),
        ];

        usort($hunks, static fn (array $a, array $b): int => [$a['start'], $a['end'] > $a['start'] ? 1 : 0, $a['side']]
            <=> [$b['start'], $b['end'] > $b['start'] ? 1 : 0, $b['side']]);

        $output = [];
        $offset = 0;
        $cursor = 0;
        $conflicts = [];

        foreach ($this->groupOverlapping($hunks) as $group) {
            $equal = array_slice($baseTokens, $cursor, $group['start'] - $cursor);
            $output = [...$output, ...$equal];
            $offset += $this->tokensLength($equal);
            $sides = array_unique(array_column($group['hunks'], 'side'));
            $oursTokensHere = $this->applyHunks($baseTokens, $group, 'ours');
            $theirsTokensHere = $this->applyHunks($baseTokens, $group, 'theirs');
            $resolved = in_array('ours', $sides, true) ? $oursTokensHere : $theirsTokensHere;

            if (count($sides) === 2 && $this->tokenKeys($oursTokensHere) !== $this->tokenKeys($theirsTokensHere)) {
                $conflicts[] = ['ours' => $this->inlineNodes($oursTokensHere), 'theirs' => $this->inlineNodes($theirsTokensHere), 'offset' => $offset];
            }

            $output = [...$output, ...$resolved];
            $offset += $this->tokensLength($resolved);
            $cursor = $group['end'];
        }

        $output = [...$output, ...array_slice($baseTokens, $cursor)];

        return [$this->inlineNodes($output), $conflicts];
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return list<array{key: string, text: string, node: array<string, mixed>|null, marks: list<array<string, mixed>>}>
     */
    private function inlineTokens(array $content, bool $whole): array
    {
        $tokens = [];

        foreach ($content as $node) {
            if (($node['type'] ?? null) !== 'text') {
                $projected = $this->project($node);
                $tokens[] = ['key' => 'n:'.json_encode($projected), 'text' => $node['type'] === 'hardBreak' ? "\n" : '', 'node' => $node, 'marks' => []];

                continue;
            }

            $marks = $this->normalizeMarks($node['marks'] ?? []);
            $markKey = json_encode($marks);
            $pieces = $whole ? [(string) $node['text']] : $this->words((string) $node['text']);

            foreach ($pieces as $piece) {
                $tokens[] = ['key' => 't:'.$markKey.':'.$piece, 'text' => $piece, 'node' => null, 'marks' => $marks];
            }
        }

        return $tokens;
    }

    /**
     * Tokens back into inline nodes, joining text with equal marks.
     *
     * @param  list<array{key: string, text: string, node: array<string, mixed>|null, marks: list<array<string, mixed>>}>  $tokens
     * @return list<array<string, mixed>>
     */
    private function inlineNodes(array $tokens): array
    {
        $nodes = [];
        $open = null;

        foreach ($tokens as $token) {
            if ($token['node'] !== null) {
                if ($open !== null) {
                    $nodes[] = $open;
                    $open = null;
                }

                $nodes[] = $token['node'];

                continue;
            }

            if ($open !== null && $this->normalizeMarks($open['marks'] ?? []) === $token['marks']) {
                $open['text'] .= $token['text'];

                continue;
            }

            if ($open !== null) {
                $nodes[] = $open;
            }

            $open = ['type' => 'text', 'text' => $token['text']];

            if ($token['marks'] !== []) {
                $open['marks'] = $token['marks'];
            }
        }

        if ($open !== null) {
            $nodes[] = $open;
        }

        return $nodes;
    }

    /**
     * Words, runs of whitespace and single punctuation characters — the
     * same split as `AutosaveTextMerge`.
     *
     * @return list<string>
     */
    private function words(string $text): array
    {
        if ($text === '') {
            return [];
        }

        preg_match_all('/\s+|[\p{L}\p{M}\p{N}_]+|./us', $text, $matches);

        return $matches[0];
    }

    /**
     * @param  list<array{key: string, text: string, node: array<string, mixed>|null, marks: list<array<string, mixed>>}>  $tokens
     */
    private function tokensLength(array $tokens): int
    {
        $length = 0;

        foreach ($tokens as $token) {
            $length += mb_strlen($token['text']);
        }

        return $length;
    }

    /**
     * @param  list<array{key: string, text: string, node: array<string, mixed>|null, marks: list<array<string, mixed>>}>  $tokens
     * @return list<string>
     */
    private function tokenKeys(array $tokens): array
    {
        return array_column($tokens, 'key');
    }

    /**
     * @param  list<array<string, mixed>>  $marks
     * @return list<array<string, mixed>>
     */
    private function normalizeMarks(array $marks): array
    {
        $normalized = array_map(function (array $mark): array {
            $mark = $this->sortKeys($mark);

            if (($mark['attrs'] ?? null) === [] || ($mark['attrs'] ?? null) === null) {
                unset($mark['attrs']);
            }

            return $mark;
        }, $marks);

        usort($normalized, static fn (array $a, array $b): int => strcmp((string) json_encode($a), (string) json_encode($b)));

        return $normalized;
    }

    // ---------------------------------------------------------------------
    // Diff3 over token keys (mirrors AutosaveTextMerge)
    // ---------------------------------------------------------------------

    /**
     * @param  list<array{key: string, text: string, node: array<string, mixed>|null, marks: list<array<string, mixed>>}>  $base
     * @param  list<array{key: string, text: string, node: array<string, mixed>|null, marks: list<array<string, mixed>>}>  $side
     * @return list<array{start: int, end: int, tokens: list<array{key: string, text: string, node: array<string, mixed>|null, marks: list<array<string, mixed>>}>, side: string}>
     */
    private function hunks(array $base, array $side, string $name): array
    {
        $pairs = $this->lcs($base, $side, static fn (array $a, array $b): float => $a['key'] === $b['key'] ? 1.0 : 0.0);
        $hunks = [];
        $i = 0;
        $j = 0;

        foreach ([...$pairs, [count($base), count($side)]] as [$bi, $sj]) {
            if ($bi > $i || $sj > $j) {
                $hunks[] = ['start' => $i, 'end' => $bi, 'tokens' => array_slice($side, $j, $sj - $j), 'side' => $name];
            }

            $i = $bi + 1;
            $j = $sj + 1;
        }

        return $hunks;
    }

    /**
     * @param  list<array{start: int, end: int, tokens: list<mixed>, side: string}>  $hunks  Sorted by start.
     * @return list<array{start: int, end: int, hunks: list<array{start: int, end: int, tokens: list<mixed>, side: string}>}>
     */
    private function groupOverlapping(array $hunks): array
    {
        $groups = [];
        $group = null;

        foreach ($hunks as $hunk) {
            if ($group !== null && $this->overlaps($group, $hunk)) {
                $group['end'] = max($group['end'], $hunk['end']);
                $group['hunks'][] = $hunk;

                continue;
            }

            if ($group !== null) {
                $groups[] = $group;
            }

            $group = ['start' => $hunk['start'], 'end' => $hunk['end'], 'hunks' => [$hunk]];
        }

        if ($group !== null) {
            $groups[] = $group;
        }

        return $groups;
    }

    /**
     * @param  array{start: int, end: int}  $a
     * @param  array{start: int, end: int}  $b
     */
    private function overlaps(array $a, array $b): bool
    {
        if (max($a['start'], $b['start']) < min($a['end'], $b['end'])) {
            return true;
        }

        return ($b['start'] === $b['end'] && $a['start'] < $b['start'] && $b['start'] < $a['end'])
            || ($a['start'] === $a['end'] && $b['start'] < $a['start'] && $a['start'] < $b['end']);
    }

    /**
     * @param  list<array{key: string, text: string, node: array<string, mixed>|null, marks: list<array<string, mixed>>}>  $base
     * @param  array{start: int, end: int, hunks: list<array{start: int, end: int, tokens: list<array{key: string, text: string, node: array<string, mixed>|null, marks: list<array<string, mixed>>}>, side: string}>}  $group
     * @return list<array{key: string, text: string, node: array<string, mixed>|null, marks: list<array<string, mixed>>}>
     */
    private function applyHunks(array $base, array $group, string $side): array
    {
        $output = [];
        $cursor = $group['start'];

        foreach ($group['hunks'] as $hunk) {
            if ($hunk['side'] !== $side) {
                continue;
            }

            $output = [...$output, ...array_slice($base, $cursor, $hunk['start'] - $cursor), ...$hunk['tokens']];
            $cursor = $hunk['end'];
        }

        return [...$output, ...array_slice($base, $cursor, $group['end'] - $cursor)];
    }

    /**
     * Heaviest common subsequence as `[i, j]` pairs; `$weight` returns 0
     * for a mismatch and the value of keeping a pair in place otherwise.
     *
     * @param  list<mixed>  $a
     * @param  list<mixed>  $b
     * @param  callable(mixed, mixed): float  $weight
     * @return list<array{0: int, 1: int}>
     */
    private function lcs(array $a, array $b, callable $weight): array
    {
        $n = count($a);
        $m = count($b);
        $table = array_fill(0, $n + 1, array_fill(0, $m + 1, 0.0));
        $weights = [];

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $w = $weights[$i][$j] = $weight($a[$i], $b[$j]);
                $table[$i][$j] = max(
                    $w > 0 ? $table[$i + 1][$j + 1] + $w : 0.0,
                    $table[$i + 1][$j],
                    $table[$i][$j + 1],
                );
            }
        }

        $pairs = [];
        $i = 0;
        $j = 0;

        while ($i < $n && $j < $m) {
            $w = $weights[$i][$j];

            if ($w > 0 && $table[$i][$j] === $table[$i + 1][$j + 1] + $w) {
                $pairs[] = [$i, $j];
                $i++;
                $j++;
            } elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $pairs;
    }

    // ---------------------------------------------------------------------
    // Node identity and comparison
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function similar(array $a, array $b): bool
    {
        $keyA = $this->idKey($a);
        $keyB = $this->idKey($b);

        if ($keyA !== null || $keyB !== null) {
            return $keyA === $keyB;
        }

        if ($this->same($a, $b)) {
            return true;
        }

        $textblocks = $this->isTextblock($a) && $this->isTextblock($b);

        if (! $textblocks && ($a['type'] ?? null) !== ($b['type'] ?? null)) {
            return false;
        }

        if ($this->isAtom($a) || $this->isAtom($b)) {
            return false;
        }

        $wordsA = $this->wordBag($a);
        $wordsB = $this->wordBag($b);

        if ($wordsA === [] && $wordsB === []) {
            return $textblocks ? ($a['type'] ?? null) === ($b['type'] ?? null) : true;
        }

        $common = count(array_intersect_key($wordsA, $wordsB));

        return $common / max(count($wordsA), count($wordsB)) >= self::SIMILARITY;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, true>
     */
    private function wordBag(array $node): array
    {
        $bag = [];

        foreach ($this->blockTexts($node) as $text) {
            preg_match_all('/[\p{L}\p{M}\p{N}_]+/u', $text, $matches);

            foreach ($matches[0] as $word) {
                $bag[mb_strtolower($word)] = true;
            }
        }

        return $bag;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function idKey(array $node): ?string
    {
        $id = $node['attrs']['id'] ?? null;

        if (! in_array($node['type'] ?? null, self::ID_TYPES, true) || ! is_scalar($id) || (string) $id === '') {
            return null;
        }

        return $node['type'].'#'.$id;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function isAtom(array $node): bool
    {
        if (in_array($node['type'] ?? null, self::BLOCK_ATOMS, true)) {
            return true;
        }

        return ! array_key_exists('content', $node) && ! $this->isTextblock($node);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function isTextblock(array $node): bool
    {
        if (in_array($node['type'] ?? null, self::TEXTBLOCK_TYPES, true)) {
            return true;
        }

        if (in_array($node['type'] ?? null, [...self::INLINE_TYPES, ...self::BLOCK_ATOMS, 'doc'], true)) {
            return false;
        }

        foreach ($node['content'] ?? [] as $child) {
            return in_array($child['type'] ?? null, self::INLINE_TYPES, true);
        }

        return false;
    }

    /**
     * Type and attributes without content.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function shell(array $node): array
    {
        unset($node['content'], $node['__conflicts']);

        return $node;
    }

    /**
     * Structural equality after projection.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function same(array $a, array $b): bool
    {
        return $this->project($a) === $this->project($b);
    }

    /**
     * The node as compared: attributes the state cast rewrites are dropped,
     * keys are sorted, marks are normalised.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function project(array $node): array
    {
        unset($node['__conflicts']);

        if (($node['type'] ?? null) === 'image' && ($node['attrs']['id'] ?? null) !== null && ($node['attrs']['id'] ?? '') !== '') {
            unset($node['attrs']['src']);
        }

        if (($node['type'] ?? null) === 'customBlock') {
            unset($node['attrs']['label'], $node['attrs']['preview'], $node['attrs']['shouldApplyProseStylingToPreview']);
        }

        if (isset($node['attrs'])) {
            $node['attrs'] = array_filter($node['attrs'], static fn (mixed $value): bool => $value !== null);

            if ($node['attrs'] === []) {
                unset($node['attrs']);
            }
        }

        if (isset($node['marks'])) {
            $node['marks'] = $this->normalizeMarks($node['marks']);

            if ($node['marks'] === []) {
                unset($node['marks']);
            }
        }

        if (isset($node['content'])) {
            $node['content'] = array_values(array_map(fn (array $child): array => $this->project($child), $node['content']));

            if ($node['content'] === []) {
                unset($node['content']);
            }
        }

        return $this->sortKeys($node);
    }

    /**
     * `$node` with projected-away attributes taken back from `$sources`
     * when it lost them (an image `src` nulled for private visibility).
     *
     * @param  array<string, mixed>  $node
     * @param  list<array<string, mixed>>  $sources
     * @return array<string, mixed>
     */
    private function restore(array $node, array $sources): array
    {
        if (($node['type'] ?? null) === 'image' && $this->idKey($node) !== null && ($node['attrs']['src'] ?? null) === null) {
            foreach ($sources as $source) {
                if ($this->idKey($source) === $this->idKey($node) && ($source['attrs']['src'] ?? null) !== null) {
                    $node['attrs']['src'] = $source['attrs']['src'];

                    break;
                }
            }
        }

        if (isset($node['content']) && is_array($node['content'])) {
            $byKey = [];

            foreach ($sources as $source) {
                foreach ($source['content'] ?? [] as $child) {
                    if (($key = $this->idKey($child)) !== null) {
                        $byKey[$key][] = $child;
                    }
                }
            }

            $pool = [];

            foreach ($sources as $source) {
                $pool = [...$pool, ...($source['content'] ?? [])];
            }

            foreach ($node['content'] as $index => $child) {
                $candidates = ($key = $this->idKey($child)) !== null ? ($byKey[$key] ?? []) : $pool;
                $node['content'][$index] = $this->restore($child, $candidates);
            }
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function sortKeys(array $node): array
    {
        ksort($node);

        foreach ($node as $key => $value) {
            if (is_array($value) && $key !== 'content' && $key !== 'marks' && ! array_is_list($value)) {
                $node[$key] = $this->sortKeys($value);
            }
        }

        return $node;
    }

    // ---------------------------------------------------------------------
    // Conflicts and positions
    // ---------------------------------------------------------------------

    /**
     * @param  list<int>  $path
     * @param  array<string, mixed>|null  $ours
     * @param  array<string, mixed>|null  $theirs
     */
    private function blockConflict(array $path, int $index, ?array $ours, ?array $theirs): void
    {
        $this->conflicts[] = ['kind' => 'block', 'block' => [...$path, $index], 'ours' => $ours, 'theirs' => $theirs];
    }

    /**
     * Turn markers and detached block conflicts into the public shape.
     *
     * @param  array<string, mixed>  $merged
     * @return list<array{kind: string, ours: string|array<string, mixed>|list<array<string, mixed>>, theirs: string|array<string, mixed>|list<array<string, mixed>>, reason: string, block: list<int>, position: int}>
     */
    private function resolveConflicts(array $merged): array
    {
        $offsets = [];
        $texts = [];
        $this->collectOffsets($merged, [], $offsets, $texts);
        $end = $texts === [] ? 0 : mb_strlen(implode("\n", $texts));
        $conflicts = [];

        $this->walkPaths($merged, [], function (array $node, array $path) use (&$conflicts, $offsets): void {
            foreach ($node['__conflicts'] ?? [] as $conflict) {
                $conflicts[] = [
                    'kind' => $conflict['kind'],
                    'ours' => $this->fragment($conflict['ours']),
                    'theirs' => $this->fragment($conflict['theirs']),
                    'reason' => 'overlap',
                    'block' => $path,
                    'position' => ($offsets[implode('.', $path)] ?? 0) + $conflict['offset'],
                ];
            }
        });

        foreach ($this->conflicts as $conflict) {
            $conflicts[] = [
                'kind' => 'block',
                'ours' => $this->fragment($conflict['ours']),
                'theirs' => $this->fragment($conflict['theirs']),
                'reason' => 'overlap',
                'block' => $conflict['block'],
                'position' => $this->offsetAt($conflict['block'], $offsets, $end),
            ];
        }

        usort($conflicts, static fn (array $a, array $b): int => [$a['position'], $a['block']] <=> [$b['position'], $b['block']]);

        return $conflicts;
    }

    /**
     * Offset of the first leaf block at or after `$path`.
     *
     * @param  list<int>  $path
     * @param  array<string, int>  $offsets
     */
    private function offsetAt(array $path, array $offsets, int $end): int
    {
        $prefix = implode('.', $path);

        foreach ($offsets as $key => $offset) {
            if ($key === $prefix || str_starts_with($key, $prefix.'.')) {
                return $offset;
            }
        }

        // No block at that index: the next sibling, or the end of the parent.
        $parent = array_slice($path, 0, -1);
        $index = $path[count($path) - 1] ?? 0;

        foreach ($offsets as $key => $offset) {
            $parts = array_map('intval', explode('.', $key));

            if (array_slice($parts, 0, count($parent)) === $parent && ($parts[count($parent)] ?? -1) > $index) {
                return $offset;
            }
        }

        return $end;
    }

    /**
     * Plain-text offset of every leaf block, keyed by dotted path.
     *
     * @param  array<string, mixed>  $node
     * @param  list<int>  $path
     * @param  array<string, int>  $offsets
     * @param  list<string>  $texts
     */
    private function collectOffsets(array $node, array $path, array &$offsets, array &$texts): void
    {
        if ($this->isLeafBlock($node)) {
            $offsets[implode('.', $path)] = $texts === [] ? 0 : mb_strlen(implode("\n", $texts)) + 1;
            $texts[] = $this->inlineText($node);

            return;
        }

        foreach ($node['content'] ?? [] as $index => $child) {
            $this->collectOffsets($child, [...$path, $index], $offsets, $texts);
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function isLeafBlock(array $node): bool
    {
        return ($node['type'] ?? null) !== 'doc' && ($this->isTextblock($node) || $this->isAtom($node));
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function blockTexts(array $node): array
    {
        if ($this->isLeafBlock($node)) {
            return [$this->inlineText($node)];
        }

        $texts = [];

        foreach ($node['content'] ?? [] as $child) {
            $texts = [...$texts, ...$this->blockTexts($child)];
        }

        return $texts;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function inlineText(array $node): string
    {
        $text = '';

        foreach ($node['content'] ?? [] as $child) {
            $text .= match ($child['type'] ?? null) {
                'text' => (string) ($child['text'] ?? ''),
                'hardBreak' => "\n",
                default => '',
            };
        }

        return $text;
    }

    /**
     * A node (or nothing) as a fragment in the column format.
     *
     * @param  array<string, mixed>|null  $node
     * @return string|list<array<string, mixed>>
     */
    private function fragment(?array $node): string|array
    {
        $doc = ['type' => 'doc', 'content' => $node === null ? [] : [$this->stripMarkers($node)]];

        return $this->json ? ($doc['content']) : $this->editor->setContent($doc)->getHTML();
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function stripMarkers(array $node): array
    {
        unset($node['__conflicts']);

        if (isset($node['content']) && is_array($node['content'])) {
            $node['content'] = array_values(array_map(fn (array $child): array => $this->stripMarkers($child), $node['content']));
        }

        return $node;
    }

    // ---------------------------------------------------------------------
    // Documents
    // ---------------------------------------------------------------------

    /**
     * @param  string|array<string, mixed>|null  $value
     * @return array<string, mixed>
     */
    private function doc(string|array|null $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            $value = ['type' => 'doc', 'content' => []];
        }

        // Attribute values parsed from HTML may arrive as objects (a custom
        // block's `config`); compare and serialise plain arrays only.
        $doc = json_decode((string) json_encode($this->editor->setContent($value)->getDocument()), true);

        return is_array($doc) ? $doc : ['type' => 'doc', 'content' => []];
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return string|array<string, mixed>
     */
    private function serialize(array $doc): string|array
    {
        $this->editor->setContent($doc);

        return $this->json ? $this->editor->getDocument() : $this->editor->getHTML();
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  callable(array<string, mixed>): void  $visit
     */
    private function walk(array $node, callable $visit): void
    {
        $visit($node);

        foreach ($node['content'] ?? [] as $child) {
            $this->walk($child, $visit);
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<int>  $path
     * @param  callable(array<string, mixed>, list<int>): void  $visit
     */
    private function walkPaths(array $node, array $path, callable $visit): void
    {
        $visit($node, $path);

        foreach ($node['content'] ?? [] as $index => $child) {
            $this->walkPaths($child, [...$path, $index], $visit);
        }
    }
}
