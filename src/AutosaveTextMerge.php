<?php

namespace Lenorix\FilamentAutosave;

use InvalidArgumentException;

/**
 * Plain-text merging for fields two editors touch at once.
 *
 * Two entry points, both dependency-free and deterministic:
 *
 * - `merge()` is a word-level diff3: given the value both editors started
 *   from, non-overlapping changes from both sides are combined and an
 *   overlapping range is resolved last-write-wins (`ours`) in that range
 *   only, reporting what was discarded.
 * - `apply()` plays a diff-match-patch text patch (`diff(base, ours)`, built
 *   by the client or by `makePatch()`) on top of the record's current value,
 *   locating each hunk by its context with the same fuzzy matching the
 *   JavaScript library uses (Bitap, distance-weighted). A hunk whose own
 *   text the other editor changed is still forced in — last write wins in
 *   that range — and reported as a conflict together with the text it
 *   replaced.
 *
 * All positions are code-point offsets; the JavaScript library counts UTF-16
 * units, but patch coordinates are only hints refined by context matching,
 * so the two agree in practice.
 *
 * @internal
 */
final class AutosaveTextMerge
{
    private const EQUAL = 0;

    private const DELETE = -1;

    private const INSERT = 1;

    /** Match quality below which a fuzzy context match is rejected (0 = exact, 1 = anything). */
    private const MATCH_THRESHOLD = 0.5;

    /** Distance (in characters) from the expected location that costs as much as a full mismatch. */
    private const MATCH_DISTANCE = 1000;

    /** Bitap pattern width; longer hunks are matched by their two ends. */
    private const MATCH_MAX_BITS = 32;

    /** Context characters kept around a hunk when a patch is made. */
    private const PATCH_MARGIN = 4;

    /** Fraction of a long hunk the fuzzy match may fail to cover before the hunk is treated as missing. */
    private const PATCH_DELETE_THRESHOLD = 0.5;

    /** Edit-script size above which a diff degrades to "replace everything between the common ends". */
    private const MAX_EDIT_DISTANCE = 1000;

    // ---------------------------------------------------------------------
    // Three-way merge
    // ---------------------------------------------------------------------

    /**
     * Combine two edits of `$base`. Overlapping ranges keep `$ours`.
     *
     * @throws InvalidArgumentException When a value is not valid UTF-8.
     */
    public function merge(string $base, string $ours, string $theirs): AutosaveMergeResult
    {
        $this->assertUtf8($base);
        $this->assertUtf8($ours);
        $this->assertUtf8($theirs);

        if ($ours === $theirs) {
            return new AutosaveMergeResult($ours, [], [], []);
        }

        $baseTokens = $this->tokenize($base);
        $hunks = [
            ...$this->hunks($baseTokens, $this->tokenize($ours), 'ours'),
            ...$this->hunks($baseTokens, $this->tokenize($theirs), 'theirs'),
        ];

        usort($hunks, static fn (array $a, array $b): int => [$a['start'], $a['end'] > $a['start'] ? 1 : 0, $a['side']]
            <=> [$b['start'], $b['end'] > $b['start'] ? 1 : 0, $b['side']]);

        $output = '';
        $position = 0;
        $cursor = 0;
        $oursHunks = [];
        $theirsHunks = [];
        $conflicts = [];
        $previousInsertion = null;

        foreach ($this->groupOverlapping($hunks) as $group) {
            $equal = implode('', array_slice($baseTokens, $cursor, $group['start'] - $cursor));
            $output .= $equal;
            $position += mb_strlen($equal);
            $from = implode('', array_slice($baseTokens, $group['start'], $group['end'] - $group['start']));
            $sides = array_unique(array_column($group['hunks'], 'side'));
            $oursText = $this->applyHunks($baseTokens, $group, 'ours');
            $theirsText = $this->applyHunks($baseTokens, $group, 'theirs');
            $resolved = in_array('ours', $sides, true) ? $oursText : $theirsText;

            // Two insertions at the same point from different editors are
            // appended one after the other; keep them readable when neither
            // brought its own separator.
            if ($group['start'] === $group['end'] && $previousInsertion === $group['start']
                && $resolved !== '' && $output !== ''
                && ! preg_match('/\s$/u', $output) && ! preg_match('/^\s/u', $resolved)) {
                $output .= ' ';
                $position++;
            }

            if (count($sides) === 2 && $oursText !== $theirsText) {
                $conflicts[] = ['ours' => $oursText, 'theirs' => $theirsText, 'position' => $position];
            }

            foreach ($group['hunks'] as $hunk) {
                if ($hunk['side'] === 'ours' || count($sides) === 1) {
                    $report = ['position' => $position, 'from' => $from, 'to' => $resolved];
                    $hunk['side'] === 'ours' ? $oursHunks[] = $report : $theirsHunks[] = $report;
                }
            }

            $output .= $resolved;
            $position += mb_strlen($resolved);
            $cursor = $group['end'];
            $previousInsertion = $group['start'] === $group['end'] ? $group['start'] : null;
        }

        $output .= implode('', array_slice($baseTokens, $cursor));

        return new AutosaveMergeResult($output, $this->uniqueHunks($oursHunks), $this->uniqueHunks($theirsHunks), $conflicts);
    }

    // ---------------------------------------------------------------------
    // Patches
    // ---------------------------------------------------------------------

    /**
     * Patch text turning `$before` into `$after`, in diff-match-patch's
     * format (word-level hunks, character offsets, context margins).
     */
    public function makePatch(string $before, string $after): string
    {
        if ($before === $after) {
            return '';
        }

        $diffs = [];

        foreach ($this->diff($this->tokenize($before), $this->tokenize($after)) as [$op, $tokens]) {
            $diffs[] = [$op, $this->chars(implode('', $tokens))];
        }

        $text = $this->chars($before);
        $patches = [];
        $patch = null;
        $count1 = 0;
        $count2 = 0;
        $prepatch = $text;
        $postpatch = $text;
        $last = count($diffs) - 1;

        foreach ($diffs as $index => [$op, $chars]) {
            $length = count($chars);

            if ($patch === null && $op !== self::EQUAL) {
                $patch = ['diffs' => [], 'start1' => $count1, 'start2' => $count2, 'length1' => 0, 'length2' => 0];
            }

            if ($op === self::INSERT) {
                $patch['diffs'][] = [$op, $chars];
                $patch['length2'] += $length;
                array_splice($postpatch, $count2, 0, $chars);
            } elseif ($op === self::DELETE) {
                $patch['diffs'][] = [$op, $chars];
                $patch['length1'] += $length;
                array_splice($postpatch, $count2, $length);
            } elseif ($length <= 2 * self::PATCH_MARGIN && $patch !== null && $index !== $last) {
                $patch['diffs'][] = [$op, $chars];
                $patch['length1'] += $length;
                $patch['length2'] += $length;
            } elseif ($length >= 2 * self::PATCH_MARGIN && $patch !== null) {
                $patches[] = $this->addContext($patch, $prepatch);
                $patch = null;
                $prepatch = $postpatch;
                $count1 = $count2;
            }

            if ($op !== self::INSERT) {
                $count1 += $length;
            }

            if ($op !== self::DELETE) {
                $count2 += $length;
            }
        }

        if ($patch !== null) {
            $patches[] = $this->addContext($patch, $prepatch);
        }

        return $this->patchesToText($patches);
    }

    /**
     * Play `$patch` (diff-match-patch text) on `$theirs`.
     *
     * @throws InvalidArgumentException When the patch text is malformed or a value is not valid UTF-8.
     */
    public function apply(string $theirs, string $patch): AutosaveApplyResult
    {
        $patches = $this->parsePatches($patch);

        if ($patches === []) {
            return new AutosaveApplyResult($theirs, [], []);
        }

        $padding = $this->chars(implode('', array_map('chr', range(1, self::PATCH_MARGIN))));
        $patches = $this->addPadding($patches, $padding);
        $text = [...$padding, ...$this->chars($theirs), ...$padding];
        $pad = count($padding);
        $delta = 0;
        $applied = [];
        $conflicts = [];

        foreach ($patches as $index => $current) {
            $expected = $current['start2'] + $delta;
            $text1 = $this->diffText($current['diffs'], self::DELETE);
            $length1 = count($text1);
            $end = -1;

            if ($length1 > self::MATCH_MAX_BITS) {
                $start = $this->matchMain($text, array_slice($text1, 0, self::MATCH_MAX_BITS), $expected);

                if ($start !== -1) {
                    $end = $this->matchMain($text, array_slice($text1, -self::MATCH_MAX_BITS), $expected + $length1 - self::MATCH_MAX_BITS);

                    if ($end === -1 || $start >= $end) {
                        $start = -1;
                    }
                }
            } else {
                $start = $this->matchMain($text, $text1, $expected);
            }

            if ($start === -1) {
                [$text, $applied[$index], $conflict, $delta] = $this->forceHunk($text, $current, $expected, $delta);
            } else {
                $delta = $start - $expected;
                $text2 = $end === -1
                    ? array_slice($text, $start, $length1)
                    : array_slice($text, $start, $end + self::MATCH_MAX_BITS - $start);
                [$text, $applied[$index], $conflict, $delta] = $text1 === $text2
                    ? [$this->splice($text, $start, $length1, $this->diffText($current['diffs'], self::INSERT)), true, null, $delta]
                    : $this->applyFuzzy($text, $current, $text1, $text2, $start, $expected, $delta);
            }

            if ($conflict !== null) {
                $conflict['position'] = max(0, $conflict['position'] - $pad);
                $conflicts[] = $conflict;
            }
        }

        return new AutosaveApplyResult(implode('', array_slice($text, $pad, count($text) - 2 * $pad)), $applied, $conflicts);
    }

    // ---------------------------------------------------------------------
    // Patch application internals
    // ---------------------------------------------------------------------

    /**
     * The hunk's context was found but with differences. Differences confined
     * to the context are tolerated (the other editor typed nearby); a
     * difference inside the hunk's own text means both editors changed the
     * same words, so ours is forced over theirs and reported.
     *
     * @param  list<string>  $text
     * @param  array{diffs: list<array{0: int, 1: list<string>}>, start1: int, start2: int, length1: int, length2: int}  $patch
     * @param  list<string>  $text1
     * @param  list<string>  $text2
     * @return array{0: list<string>, 1: bool, 2: array{ours: string, theirs: string, position: int}|null, 3: int}
     */
    private function applyFuzzy(array $text, array $patch, array $text1, array $text2, int $start, int $expected, int $delta): array
    {
        $diffs = $this->diff($text1, $text2);
        $length1 = count($text1);

        if ($length1 > self::MATCH_MAX_BITS && $this->levenshtein($diffs) / $length1 > self::PATCH_DELETE_THRESHOLD) {
            return $this->forceHunk($text, $patch, $expected, $delta);
        }

        [$prefix, $middle, $suffix] = $this->splitContext($patch['diffs']);
        $prefixLength = count($prefix);
        $middle1 = $this->diffText($middle, self::DELETE);
        $middle2 = $this->diffText($middle, self::INSERT);
        $from = $this->xIndex($diffs, $prefixLength);
        $to = $this->xIndex($diffs, $prefixLength + count($middle1));
        $theirsMiddle = array_slice($text2, $from, $to - $from);

        if ($theirsMiddle === $middle1) {
            $index1 = 0;

            foreach ($patch['diffs'] as [$op, $chars]) {
                if ($op !== self::EQUAL) {
                    $index2 = $this->xIndex($diffs, $index1);
                }

                if ($op === self::INSERT) {
                    $text = $this->splice($text, $start + $index2, 0, $chars);
                } elseif ($op === self::DELETE) {
                    $text = $this->splice($text, $start + $index2, $this->xIndex($diffs, $index1 + count($chars)) - $index2, []);
                }

                // As in diff-match-patch: a deletion shrinks the text in
                // place, so the following insertion lands on the same index.
                if ($op !== self::DELETE) {
                    $index1 += count($chars);
                }
            }

            return [$text, true, null, $delta];
        }

        if ($theirsMiddle === $middle2) {
            // The other editor already made this exact change.
            return [$text, true, null, $delta];
        }

        return [
            $this->splice($text, $start + $from, $to - $from, $middle2),
            false,
            ['ours' => implode('', $middle2), 'theirs' => implode('', $theirsMiddle), 'position' => $start + $from],
            $delta,
        ];
    }

    /**
     * The hunk's text is gone: the other editor rewrote it. Locate the range
     * between the hunk's leading and trailing context (each on its own,
     * falling back to the expected position) and put ours there.
     *
     * @param  list<string>  $text
     * @param  array{diffs: list<array{0: int, 1: list<string>}>, start1: int, start2: int, length1: int, length2: int}  $patch
     * @return array{0: list<string>, 1: bool, 2: array{ours: string, theirs: string, position: int}|null, 3: int}
     */
    private function forceHunk(array $text, array $patch, int $expected, int $delta): array
    {
        [$prefix, $middle, $suffix] = $this->splitContext($patch['diffs']);
        $middle1 = $this->diffText($middle, self::DELETE);
        $middle2 = $this->diffText($middle, self::INSERT);
        $length = count($text);
        $prefixLength = count($prefix);
        $middleLength = count($middle1);

        $prefixPattern = array_slice($prefix, -self::MATCH_MAX_BITS);
        $prefixAt = $this->matchMain($text, $prefixPattern, $expected + $prefixLength - count($prefixPattern));
        $prefixEnd = $prefixAt === -1 ? null : $prefixAt + count($prefixPattern);

        $suffixPattern = array_slice($suffix, 0, self::MATCH_MAX_BITS);
        $suffixStart = $suffix === [] ? null : $this->matchMain($text, $suffixPattern, $expected + $prefixLength + $middleLength);
        $suffixStart = $suffixStart === -1 ? null : $suffixStart;

        $tolerance = max(2 * $middleLength, $middleLength + 2 * self::MATCH_MAX_BITS);

        if ($prefixEnd !== null && $suffixStart !== null && $suffixStart >= $prefixEnd && $suffixStart - $prefixEnd <= $tolerance) {
            $zoneStart = $prefixEnd;
            $zoneLength = $suffixStart - $prefixEnd;
        } elseif ($prefixEnd !== null) {
            $zoneStart = min($prefixEnd, $length);
            $zoneLength = min($middleLength, $length - $zoneStart);
        } elseif ($suffixStart !== null) {
            $zoneStart = max(0, $suffixStart - $middleLength);
            $zoneLength = $suffixStart - $zoneStart;
        } else {
            $zoneStart = max(0, min($expected + $prefixLength, $length));
            $zoneLength = min($middleLength, $length - $zoneStart);
        }

        $theirs = array_slice($text, $zoneStart, $zoneLength);
        $delta = $zoneStart - $prefixLength - $expected;

        if ($theirs === $middle2) {
            return [$text, true, null, $delta];
        }

        return [
            $this->splice($text, $zoneStart, $zoneLength, $middle2),
            false,
            ['ours' => implode('', $middle2), 'theirs' => implode('', $theirs), 'position' => $zoneStart],
            $delta,
        ];
    }

    /**
     * @param  list<array{0: int, 1: list<string>}>  $diffs
     * @return array{0: list<string>, 1: list<array{0: int, 1: list<string>}>, 2: list<string>}
     */
    private function splitContext(array $diffs): array
    {
        $prefix = [];
        $suffix = [];

        if ($diffs !== [] && $diffs[0][0] === self::EQUAL) {
            $prefix = array_shift($diffs)[1];
        }

        if ($diffs !== [] && $diffs[array_key_last($diffs)][0] === self::EQUAL) {
            $suffix = array_pop($diffs)[1];
        }

        return [$prefix, $diffs, $suffix];
    }

    /**
     * Pad the text so hunks at either end still carry full context.
     *
     * @param  list<array{diffs: list<array{0: int, 1: list<string>}>, start1: int, start2: int, length1: int, length2: int}>  $patches
     * @param  list<string>  $padding
     * @return list<array{diffs: list<array{0: int, 1: list<string>}>, start1: int, start2: int, length1: int, length2: int}>
     */
    private function addPadding(array $patches, array $padding): array
    {
        $length = count($padding);

        foreach ($patches as &$patch) {
            $patch['start1'] += $length;
            $patch['start2'] += $length;
        }

        unset($patch);

        $first = &$patches[0];

        if ($first['diffs'] === [] || $first['diffs'][0][0] !== self::EQUAL) {
            array_unshift($first['diffs'], [self::EQUAL, $padding]);
            $first['start1'] -= $length;
            $first['start2'] -= $length;
            $first['length1'] += $length;
            $first['length2'] += $length;
        } elseif ($length > count($first['diffs'][0][1])) {
            $extra = $length - count($first['diffs'][0][1]);
            $first['diffs'][0][1] = [...array_slice($padding, count($first['diffs'][0][1])), ...$first['diffs'][0][1]];
            $first['start1'] -= $extra;
            $first['start2'] -= $extra;
            $first['length1'] += $extra;
            $first['length2'] += $extra;
        }

        unset($first);

        $last = &$patches[array_key_last($patches)];
        $lastIndex = array_key_last($last['diffs']);

        if ($lastIndex === null || $last['diffs'][$lastIndex][0] !== self::EQUAL) {
            $last['diffs'][] = [self::EQUAL, $padding];
            $last['length1'] += $length;
            $last['length2'] += $length;
        } elseif ($length > count($last['diffs'][$lastIndex][1])) {
            $extra = $length - count($last['diffs'][$lastIndex][1]);
            $last['diffs'][$lastIndex][1] = [...$last['diffs'][$lastIndex][1], ...array_slice($padding, 0, $extra)];
            $last['length1'] += $extra;
            $last['length2'] += $extra;
        }

        unset($last);

        return $patches;
    }

    /**
     * Best position of `$pattern` in `$text` near `$loc`, or -1.
     *
     * @param  list<string>  $text
     * @param  list<string>  $pattern
     */
    private function matchMain(array $text, array $pattern, int $loc): int
    {
        $loc = max(0, min($loc, count($text)));

        if ($text === $pattern) {
            return 0;
        }

        if ($text === []) {
            return -1;
        }

        if (array_slice($text, $loc, count($pattern)) === $pattern) {
            return $loc;
        }

        return $this->matchBitap($text, $pattern, $loc);
    }

    /**
     * @param  list<string>  $text
     * @param  list<string>  $pattern
     */
    private function matchBitap(array $text, array $pattern, int $loc): int
    {
        $patternLength = count($pattern);
        $textLength = count($text);

        if ($patternLength === 0) {
            return $loc;
        }

        if ($patternLength > self::MATCH_MAX_BITS) {
            throw new InvalidArgumentException('Pattern too long for this application.');
        }

        $alphabet = [];

        foreach ($pattern as $index => $char) {
            $alphabet[$char] = ($alphabet[$char] ?? 0) | (1 << ($patternLength - $index - 1));
        }

        $score = fn (int $errors, int $x): float => $errors / $patternLength + abs($loc - $x) / self::MATCH_DISTANCE;

        $threshold = self::MATCH_THRESHOLD;
        $bestLoc = $this->indexOf($text, $pattern, $loc);

        if ($bestLoc !== -1) {
            $threshold = min($score(0, $bestLoc), $threshold);
            $bestLoc = $this->lastIndexOf($text, $pattern, $loc + $patternLength);

            if ($bestLoc !== -1) {
                $threshold = min($score(0, $bestLoc), $threshold);
            }
        }

        $matchMask = 1 << ($patternLength - 1);
        $bestLoc = -1;
        $binMax = $patternLength + $textLength;
        $lastRd = [];

        for ($d = 0; $d < $patternLength; $d++) {
            $binMin = 0;
            $binMid = $binMax;

            while ($binMin < $binMid) {
                if ($score($d, $loc + $binMid) <= $threshold) {
                    $binMin = $binMid;
                } else {
                    $binMax = $binMid;
                }

                $binMid = intdiv($binMax - $binMin, 2) + $binMin;
            }

            $binMax = $binMid;
            $start = max(1, $loc - $binMid + 1);
            $finish = min($loc + $binMid, $textLength) + $patternLength;
            $rd = [];
            $rd[$finish + 1] = (1 << $d) - 1;

            for ($j = $finish; $j >= $start; $j--) {
                $charMatch = $textLength <= $j - 1 ? 0 : ($alphabet[$text[$j - 1]] ?? 0);

                if ($d === 0) {
                    $rd[$j] = (($rd[$j + 1] << 1) | 1) & $charMatch;
                } else {
                    $rd[$j] = ((($rd[$j + 1] << 1) | 1) & $charMatch)
                        | (((($lastRd[$j + 1] ?? 0) | ($lastRd[$j] ?? 0)) << 1) | 1)
                        | ($lastRd[$j + 1] ?? 0);
                }

                if ($rd[$j] & $matchMask) {
                    $current = $score($d, $j - 1);

                    if ($current <= $threshold) {
                        $threshold = $current;
                        $bestLoc = $j - 1;

                        if ($bestLoc > $loc) {
                            $start = max(1, 2 * $loc - $bestLoc);
                        } else {
                            break;
                        }
                    }
                }
            }

            if ($score($d + 1, $loc) > $threshold) {
                break;
            }

            $lastRd = $rd;
        }

        return $bestLoc;
    }

    /**
     * @param  list<string>  $text
     * @param  list<string>  $pattern
     */
    private function indexOf(array $text, array $pattern, int $from = 0): int
    {
        $from = max(0, $from);
        $patternLength = count($pattern);
        $limit = count($text) - $patternLength;

        for ($i = $from; $i <= $limit; $i++) {
            if ($this->matchesAt($text, $pattern, $i)) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * @param  list<string>  $text
     * @param  list<string>  $pattern
     */
    private function lastIndexOf(array $text, array $pattern, int $from): int
    {
        $patternLength = count($pattern);

        for ($i = min($from, count($text) - $patternLength); $i >= 0; $i--) {
            if ($this->matchesAt($text, $pattern, $i)) {
                return $i;
            }
        }

        return -1;
    }

    /**
     * @param  list<string>  $text
     * @param  list<string>  $pattern
     */
    private function matchesAt(array $text, array $pattern, int $at): bool
    {
        foreach ($pattern as $offset => $char) {
            if (($text[$at + $offset] ?? null) !== $char) {
                return false;
            }
        }

        return true;
    }

    // ---------------------------------------------------------------------
    // Patch text
    // ---------------------------------------------------------------------

    /**
     * @return list<array{diffs: list<array{0: int, 1: list<string>}>, start1: int, start2: int, length1: int, length2: int}>
     */
    private function parsePatches(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $lines = explode("\n", $text);
        $count = count($lines);
        $patches = [];
        $pointer = 0;

        while ($pointer < $count) {
            if (! preg_match('/^@@ -(\d+),?(\d*) \+(\d+),?(\d*) @@$/', $lines[$pointer], $m)) {
                throw new InvalidArgumentException('Invalid patch string: '.$lines[$pointer]);
            }

            [$start1, $length1] = $this->parseCoordinates((int) $m[1], $m[2]);
            [$start2, $length2] = $this->parseCoordinates((int) $m[3], $m[4]);
            $diffs = [];
            $pointer++;

            while ($pointer < $count) {
                $line = $lines[$pointer];
                $sign = $line === '' ? '' : $line[0];
                $chars = $this->chars(rawurldecode(substr($line, 1)));

                if ($sign === '-') {
                    $diffs[] = [self::DELETE, $chars];
                } elseif ($sign === '+') {
                    $diffs[] = [self::INSERT, $chars];
                } elseif ($sign === ' ') {
                    $diffs[] = [self::EQUAL, $chars];
                } elseif ($sign === '@') {
                    break;
                } elseif ($sign !== '') {
                    throw new InvalidArgumentException('Invalid patch mode "'.$sign.'" in: '.$line);
                }

                $pointer++;
            }

            $patches[] = compact('diffs', 'start1', 'start2', 'length1', 'length2');
        }

        return $patches;
    }

    /** @return array{0: int, 1: int} */
    private function parseCoordinates(int $start, string $length): array
    {
        if ($length === '') {
            return [$start - 1, 1];
        }

        if ($length === '0') {
            return [$start, 0];
        }

        return [$start - 1, (int) $length];
    }

    /**
     * @param  list<array{diffs: list<array{0: int, 1: list<string>}>, start1: int, start2: int, length1: int, length2: int}>  $patches
     */
    private function patchesToText(array $patches): string
    {
        $text = '';

        foreach ($patches as $patch) {
            $text .= '@@ -'.$this->coordinates($patch['start1'], $patch['length1'])
                .' +'.$this->coordinates($patch['start2'], $patch['length2'])." @@\n";

            foreach ($patch['diffs'] as [$op, $chars]) {
                $text .= match ($op) {
                    self::INSERT => '+',
                    self::DELETE => '-',
                    default => ' ',
                }.$this->encodeUri(implode('', $chars))."\n";
            }
        }

        return $text;
    }

    private function coordinates(int $start, int $length): string
    {
        return match ($length) {
            0 => $start.',0',
            1 => (string) ($start + 1),
            default => ($start + 1).','.$length,
        };
    }

    /** JavaScript's encodeURI(), with spaces left bare as diff-match-patch does. */
    private function encodeUri(string $text): string
    {
        return strtr(rawurlencode($text), [
            '%20' => ' ', '%3B' => ';', '%2C' => ',', '%2F' => '/', '%3F' => '?', '%3A' => ':',
            '%40' => '@', '%26' => '&', '%3D' => '=', '%2B' => '+', '%24' => '$', '%21' => '!',
            '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')', '%23' => '#', '%7E' => '~',
        ]);
    }

    /**
     * Grow a patch's context until it is unique in the text.
     *
     * @param  array{diffs: list<array{0: int, 1: list<string>}>, start1: int, start2: int, length1: int, length2: int}  $patch
     * @param  list<string>  $text
     * @return array{diffs: list<array{0: int, 1: list<string>}>, start1: int, start2: int, length1: int, length2: int}
     */
    private function addContext(array $patch, array $text): array
    {
        if ($text === []) {
            return $patch;
        }

        $pattern = array_slice($text, $patch['start2'], $patch['length1']);
        $padding = 0;

        while (! $this->occursOnce($text, $pattern) && count($pattern) < self::MATCH_MAX_BITS - 2 * self::PATCH_MARGIN) {
            $padding += self::PATCH_MARGIN;
            $from = max(0, $patch['start2'] - $padding);
            $pattern = array_slice($text, $from, $patch['start2'] + $patch['length1'] + $padding - $from);
        }

        $padding += self::PATCH_MARGIN;
        $from = max(0, $patch['start2'] - $padding);
        $prefix = array_slice($text, $from, $patch['start2'] - $from);
        $suffix = array_slice($text, $patch['start2'] + $patch['length1'], $padding);

        if ($prefix !== []) {
            array_unshift($patch['diffs'], [self::EQUAL, $prefix]);
        }

        if ($suffix !== []) {
            $patch['diffs'][] = [self::EQUAL, $suffix];
        }

        $patch['start1'] -= count($prefix);
        $patch['start2'] -= count($prefix);
        $patch['length1'] += count($prefix) + count($suffix);
        $patch['length2'] += count($prefix) + count($suffix);

        return $patch;
    }

    /**
     * @param  list<string>  $text
     * @param  list<string>  $pattern
     */
    private function occursOnce(array $text, array $pattern): bool
    {
        if ($pattern === []) {
            return $text === [];
        }

        $haystack = implode('', $text);
        $needle = implode('', $pattern);

        return strpos($haystack, $needle) === strrpos($haystack, $needle);
    }

    // ---------------------------------------------------------------------
    // Diff primitives
    // ---------------------------------------------------------------------

    /**
     * Every tokenizer here is `/u`: on invalid UTF-8 it would yield nothing
     * and read a whole side as "deleted everything". Reject instead.
     *
     * @throws InvalidArgumentException
     */
    private function assertUtf8(string $text): void
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException('Text to merge is not valid UTF-8.');
        }
    }

    /** @return list<string> */
    private function chars(string $text): array
    {
        $this->assertUtf8($text);

        return $text === '' ? [] : preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
     * Words, runs of whitespace and single punctuation/symbol characters.
     *
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        $this->assertUtf8($text);

        if ($text === '') {
            return [];
        }

        preg_match_all('/\s+|[\p{L}\p{M}\p{N}_]+|./us', $text, $matches);

        return $matches[0];
    }

    /**
     * Changes turning `$base` into `$side` as base-index ranges.
     *
     * @param  list<string>  $base
     * @param  list<string>  $side
     * @return list<array{start: int, end: int, tokens: list<string>, side: string}>
     */
    private function hunks(array $base, array $side, string $name): array
    {
        $hunks = [];
        $index = 0;
        $open = null;

        foreach ($this->diff($base, $side) as [$op, $tokens]) {
            if ($op === self::EQUAL) {
                if ($open !== null) {
                    $hunks[] = $open;
                    $open = null;
                }

                $index += count($tokens);

                continue;
            }

            $open ??= ['start' => $index, 'end' => $index, 'tokens' => [], 'side' => $name];

            if ($op === self::DELETE) {
                $open['end'] += count($tokens);
                $index += count($tokens);
            } else {
                $open['tokens'] = [...$open['tokens'], ...$tokens];
            }
        }

        if ($open !== null) {
            $hunks[] = $open;
        }

        return $hunks;
    }

    /**
     * @param  list<array{start: int, end: int, tokens: list<string>, side: string}>  $hunks  Sorted by start.
     * @return list<array{start: int, end: int, hunks: list<array{start: int, end: int, tokens: list<string>, side: string}>}>
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
     * Ranges sharing at least one base token, or an insertion strictly
     * inside a changed range. Touching ranges and same-point insertions
     * are independent.
     *
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
     * One side's version of a group's base range.
     *
     * @param  list<string>  $base
     * @param  array{start: int, end: int, hunks: list<array{start: int, end: int, tokens: list<string>, side: string}>}  $group
     */
    private function applyHunks(array $base, array $group, string $side): string
    {
        $output = '';
        $cursor = $group['start'];

        foreach ($group['hunks'] as $hunk) {
            if ($hunk['side'] !== $side) {
                continue;
            }

            $output .= implode('', array_slice($base, $cursor, $hunk['start'] - $cursor)).implode('', $hunk['tokens']);
            $cursor = $hunk['end'];
        }

        return $output.implode('', array_slice($base, $cursor, $group['end'] - $cursor));
    }

    /**
     * @param  list<array{position: int, from: string, to: string}>  $hunks
     * @return list<array{position: int, from: string, to: string}>
     */
    private function uniqueHunks(array $hunks): array
    {
        return array_values(array_unique($hunks, SORT_REGULAR));
    }

    /**
     * Shortest edit script between two token lists (Myers), grouped into
     * runs of equal, deleted and inserted tokens.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{0: int, 1: list<string>}>
     */
    private function diff(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $prefix = 0;

        while ($prefix < $n && $prefix < $m && $a[$prefix] === $b[$prefix]) {
            $prefix++;
        }

        $suffix = 0;

        while ($suffix < $n - $prefix && $suffix < $m - $prefix && $a[$n - 1 - $suffix] === $b[$m - 1 - $suffix]) {
            $suffix++;
        }

        $ops = [];

        if ($prefix > 0) {
            $ops[] = [self::EQUAL, array_slice($a, 0, $prefix)];
        }

        foreach ($this->myers(array_slice($a, $prefix, $n - $prefix - $suffix), array_slice($b, $prefix, $m - $prefix - $suffix)) as $op) {
            $ops[] = $op;
        }

        if ($suffix > 0) {
            $ops[] = [self::EQUAL, array_slice($a, $n - $suffix)];
        }

        return $ops;
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{0: int, 1: list<string>}>
     */
    private function myers(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        if ($n === 0) {
            return $m === 0 ? [] : [[self::INSERT, $b]];
        }

        if ($m === 0) {
            return [[self::DELETE, $a]];
        }

        $max = min($n + $m, self::MAX_EDIT_DISTANCE);
        $v = [1 => 0];
        $trace = [];
        $found = false;

        for ($d = 0; $d <= $max && ! $found; $d++) {
            $trace[] = $v;

            for ($k = -$d; $k <= $d; $k += 2) {
                $x = ($k === -$d || ($k !== $d && $v[$k - 1] < $v[$k + 1])) ? $v[$k + 1] : $v[$k - 1] + 1;
                $y = $x - $k;

                while ($x < $n && $y < $m && $a[$x] === $b[$y]) {
                    $x++;
                    $y++;
                }

                $v[$k] = $x;

                if ($x >= $n && $y >= $m) {
                    $found = true;

                    break;
                }
            }
        }

        if (! $found) {
            return [[self::DELETE, $a], [self::INSERT, $b]];
        }

        $steps = [];
        $x = $n;
        $y = $m;

        for ($d = count($trace) - 1; $d >= 0; $d--) {
            $v = $trace[$d];
            $k = $x - $y;
            $previousK = ($k === -$d || ($k !== $d && $v[$k - 1] < $v[$k + 1])) ? $k + 1 : $k - 1;
            $previousX = $v[$previousK];
            $previousY = $previousX - $previousK;

            while ($x > $previousX && $y > $previousY) {
                $steps[] = [self::EQUAL, $a[$x - 1]];
                $x--;
                $y--;
            }

            if ($d > 0) {
                $steps[] = $x === $previousX ? [self::INSERT, $b[$previousY]] : [self::DELETE, $a[$previousX]];
            }

            $x = $previousX;
            $y = $previousY;
        }

        return $this->groupSteps(array_reverse($steps));
    }

    /**
     * Collapse single-token steps into runs; a mixed run becomes one delete
     * followed by one insert.
     *
     * @param  list<array{0: int, 1: string}>  $steps
     * @return list<array{0: int, 1: list<string>}>
     */
    private function groupSteps(array $steps): array
    {
        $runs = [];

        foreach ($steps as [$op, $token]) {
            $last = array_key_last($runs);

            if ($last !== null && $runs[$last][0] === $op) {
                $runs[$last][1][] = $token;
            } else {
                $runs[] = [$op, [$token]];
            }
        }

        $ops = [];
        $changed = [self::DELETE => [], self::INSERT => []];

        foreach ([...$runs, [self::EQUAL, []]] as [$op, $tokens]) {
            if ($op !== self::EQUAL) {
                $changed[$op] = [...$changed[$op], ...$tokens];

                continue;
            }

            foreach ([self::DELETE, self::INSERT] as $kind) {
                if ($changed[$kind] !== []) {
                    $ops[] = [$kind, $changed[$kind]];
                    $changed[$kind] = [];
                }
            }

            if ($tokens !== []) {
                $ops[] = [self::EQUAL, $tokens];
            }
        }

        return $ops;
    }

    /**
     * Text of one side of a diff: deletions and equalities, or insertions and equalities.
     *
     * @param  list<array{0: int, 1: list<string>}>  $diffs
     * @return list<string>
     */
    private function diffText(array $diffs, int $skip): array
    {
        $text = [];

        foreach ($diffs as [$op, $chars]) {
            if ($op !== -$skip) {
                $text = [...$text, ...$chars];
            }
        }

        return $text;
    }

    /**
     * Position in the second text matching `$loc` in the first.
     *
     * @param  list<array{0: int, 1: list<string>}>  $diffs
     */
    private function xIndex(array $diffs, int $loc): int
    {
        $chars1 = $chars2 = $last1 = $last2 = 0;
        $index = 0;

        foreach ($diffs as $index => [$op, $chars]) {
            if ($op !== self::INSERT) {
                $chars1 += count($chars);
            }

            if ($op !== self::DELETE) {
                $chars2 += count($chars);
            }

            if ($chars1 > $loc) {
                break;
            }

            $last1 = $chars1;
            $last2 = $chars2;
        }

        if ($chars1 > $loc && $diffs[$index][0] === self::DELETE) {
            return $last2;
        }

        return $last2 + ($loc - $last1);
    }

    /** @param  list<array{0: int, 1: list<string>}>  $diffs */
    private function levenshtein(array $diffs): int
    {
        $total = $insertions = $deletions = 0;

        foreach ($diffs as [$op, $chars]) {
            if ($op === self::INSERT) {
                $insertions += count($chars);
            } elseif ($op === self::DELETE) {
                $deletions += count($chars);
            } else {
                $total += max($insertions, $deletions);
                $insertions = $deletions = 0;
            }
        }

        return $total + max($insertions, $deletions);
    }

    /**
     * @param  list<string>  $text
     * @param  list<string>  $replacement
     * @return list<string>
     */
    private function splice(array $text, int $at, int $length, array $replacement): array
    {
        array_splice($text, $at, $length, $replacement);

        return $text;
    }
}
