<?php

namespace Lenorix\FilamentAutosave;

/**
 * Token-list diff and three-way hunk bookkeeping shared by the text and
 * rich-text merge engines.
 *
 * Tokens are opaque; `$key` maps one to the string compared for equality
 * (identity for plain strings). The diff trims the common prefix and suffix,
 * then runs Myers' shortest-edit-script search capped at
 * `MAX_EDIT_DISTANCE`; past the cap a change is reported as "everything
 * replaced", which keeps both time and memory linear in the input rather
 * than quadratic.
 *
 * @internal
 *
 * @template T
 */
final class AutosaveDiff3
{
    public const EQUAL = 0;

    public const DELETE = -1;

    public const INSERT = 1;

    /** Beyond this many edits a diff degrades to delete-all + insert-all. */
    public const MAX_EDIT_DISTANCE = 1000;

    /** @var callable(T): string */
    private $key;

    /** @param  (callable(T): string)|null  $key  Defaults to the token itself (strings). */
    public function __construct(?callable $key = null)
    {
        $this->key = $key ?? static fn (mixed $token): string => (string) $token;
    }

    /**
     * Shortest edit script between two token lists, grouped into runs of
     * equal, deleted and inserted tokens.
     *
     * @param  list<T>  $a
     * @param  list<T>  $b
     * @return list<array{0: int, 1: list<T>}>
     */
    public function diff(array $a, array $b): array
    {
        $keysA = array_map($this->key, $a);
        $keysB = array_map($this->key, $b);
        $n = count($a);
        $m = count($b);
        $prefix = 0;

        while ($prefix < $n && $prefix < $m && $keysA[$prefix] === $keysB[$prefix]) {
            $prefix++;
        }

        $suffix = 0;

        while ($suffix < $n - $prefix && $suffix < $m - $prefix && $keysA[$n - 1 - $suffix] === $keysB[$m - 1 - $suffix]) {
            $suffix++;
        }

        $ops = [];

        if ($prefix > 0) {
            $ops[] = [self::EQUAL, array_slice($a, 0, $prefix)];
        }

        foreach ($this->myers(
            array_slice($a, $prefix, $n - $prefix - $suffix),
            array_slice($b, $prefix, $m - $prefix - $suffix),
            array_slice($keysA, $prefix, $n - $prefix - $suffix),
            array_slice($keysB, $prefix, $m - $prefix - $suffix),
        ) as $op) {
            $ops[] = $op;
        }

        if ($suffix > 0) {
            $ops[] = [self::EQUAL, array_slice($a, $n - $suffix)];
        }

        return $ops;
    }

    /**
     * Changes turning `$base` into `$side` as base-index ranges.
     *
     * @param  list<T>  $base
     * @param  list<T>  $side
     * @return list<array{start: int, end: int, tokens: list<T>, side: string}>
     */
    public function hunks(array $base, array $side, string $name): array
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
     * @param  list<array{start: int, end: int, tokens: list<T>, side: string}>  $hunks  Sorted by start.
     * @return list<array{start: int, end: int, hunks: list<array{start: int, end: int, tokens: list<T>, side: string}>}>
     */
    public function groupOverlapping(array $hunks): array
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
    public function overlaps(array $a, array $b): bool
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
     * @param  list<T>  $base
     * @param  array{start: int, end: int, hunks: list<array{start: int, end: int, tokens: list<T>, side: string}>}  $group
     * @return list<T>
     */
    public function applyHunks(array $base, array $group, string $side): array
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
     * @param  list<T>  $a
     * @param  list<T>  $b
     * @param  list<string>  $keysA
     * @param  list<string>  $keysB
     * @return list<array{0: int, 1: list<T>}>
     */
    private function myers(array $a, array $b, array $keysA, array $keysB): array
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

                while ($x < $n && $y < $m && $keysA[$x] === $keysB[$y]) {
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
     * @param  list<array{0: int, 1: T}>  $steps
     * @return list<array{0: int, 1: list<T>}>
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
}
