<?php

use Lenorix\FilamentAutosave\AutosaveFieldTree;

test('plain field paths come back unchanged', function () {
    expect(AutosaveFieldTree::normalizePath('title', ['title' => true]))->toBe('title');
});

test('a literal key matches its own pattern', function () {
    expect(AutosaveFieldTree::matches('title', 'title'))->toBeTrue();
});

test('a wildcarded pattern matches its row keys', function () {
    expect(AutosaveFieldTree::matches('items.r2.label', 'items.*.label'))->toBeTrue();
});

test('a pattern does not match extra or fewer segments', function () {
    expect(AutosaveFieldTree::matches('items.label', 'items.*.label'))->toBeFalse();
    expect(AutosaveFieldTree::matches('items.r2.depth.label', 'items.*.label'))->toBeFalse();
});

test('a wildcard does not cover a divergent literal segment', function () {
    expect(AutosaveFieldTree::matches('settings.other', 'settings.name'))->toBeFalse();
});

test('row keys beneath a declared repeater become wildcards', function () {
    expect(AutosaveFieldTree::normalizePath('items.row1.label', ['items' => true]))
        ->toBe('items.*.label');
});

test('nested group paths stay literal', function () {
    expect(AutosaveFieldTree::normalizePath('settings.name', ['settings.name' => true]))
        ->toBe('settings.name');
});

test('already-wildcarded row keys survive normalization', function () {
    expect(AutosaveFieldTree::normalizePath('items.*.label', ['items' => true, 'items.*.label' => true]))
        ->toBe('items.*.label');
});

test('a direct child of a declared group reads as a row key', function () {
    expect(AutosaveFieldTree::normalizePath('a.b.c', ['a.b' => true]))->toBe('a.b.*');
});

test('an empty flat list builds an empty tree', function () {
    expect(AutosaveFieldTree::buildTree([]))->toBe([]);
});

test('flat patterns expand into a nested leaf tree', function () {
    expect(AutosaveFieldTree::buildTree(['title', 'settings.name']))
        ->toBe(['title' => [], 'settings' => ['name' => []]]);
});

test('wildcard segments become nested branches', function () {
    expect(AutosaveFieldTree::buildTree(['items.*.label']))
        ->toBe(['items' => ['*' => ['label' => []]]]);
});

test('pruning keeps only the declared top-level branches', function () {
    expect(AutosaveFieldTree::prune(['a' => 1, 'b' => 2], ['a' => []]))->toBe(['a' => 1]);
});

test('pruning strips undeclared keys inside nested groups', function () {
    expect(AutosaveFieldTree::prune(
        ['settings' => ['name' => 'x', 'is_admin' => 1]],
        ['settings' => ['name' => []]],
    ))->toBe(['settings' => ['name' => 'x']]);
});

test('pruning applies wildcard branches to every row', function () {
    expect(AutosaveFieldTree::prune(
        ['items' => ['r1' => ['label' => 'a'], 'r2' => ['nope' => 1]]],
        ['items' => ['*' => ['label' => []]]],
    ))->toBe(['items' => ['r1' => ['label' => 'a'], 'r2' => []]]);
});

test('pruning with an empty tree keeps the whole state', function () {
    expect(AutosaveFieldTree::prune(['a' => 1], []))->toBe(['a' => 1]);
});

test('matching a literal path returns it', function () {
    expect(AutosaveFieldTree::matchPaths(['title' => 'x'], 'title'))->toBe(['title']);
});

test('matching a missing literal path returns nothing', function () {
    expect(AutosaveFieldTree::matchPaths(['other' => 'x'], 'title'))->toBe([]);
});

test('wildcards expand to every concrete row path', function () {
    $data = ['items' => ['r1' => ['label' => 'a'], 'r2' => ['label' => 'b']]];

    expect(AutosaveFieldTree::matchPaths($data, 'items.*.label'))
        ->toBe(['items.r1.label', 'items.r2.label']);
});

test('wildcards over an empty collection return nothing', function () {
    expect(AutosaveFieldTree::matchPaths(['items' => []], 'items.*.label'))->toBe([]);
});

test('wildcards over a non-array value return nothing', function () {
    expect(AutosaveFieldTree::matchPaths(['a' => 5], 'a.*'))->toBe([]);
});

test('path completion accepts a fully present literal path', function () {
    expect(AutosaveFieldTree::isComplete(['title' => 'a'], 'title'))->toBeTrue();
});

test('path completion rejects a missing key', function () {
    expect(AutosaveFieldTree::isComplete(['other' => 'a'], 'title'))->toBeFalse();
});

test('path completion accepts a fully present wildcard path', function () {
    expect(AutosaveFieldTree::isComplete(['items' => ['r1' => ['label' => 'a']]], 'items.*.label'))
        ->toBeTrue();
});

test('path completion rejects rows that miss a nested key', function () {
    expect(AutosaveFieldTree::isComplete(['items' => ['r1' => []]], 'items.*.label'))
        ->toBeFalse();
});

test('path completion accepts an empty collection', function () {
    expect(AutosaveFieldTree::isComplete(['items' => []], 'items.*.label'))->toBeTrue();
});

test('eachMatch visits every concrete path for every field pattern', function () {
    $data = ['title' => 'a', 'items' => ['r1' => ['label' => 'x'], 'r2' => ['label' => 'y']]];
    $visits = [];

    AutosaveFieldTree::eachMatch(
        $data,
        ['title' => [1], 'items.*.label' => [2]],
        function (array $data, array $fieldSet, string $match) use (&$visits) {
            $visits[] = [$fieldSet, $match];
        },
    );

    expect($visits)->toBe([
        [[1], 'title'],
        [[2], 'items.r1.label'],
        [[2], 'items.r2.label'],
    ]);
});

test('eachMatch lets callbacks mutate the data array', function () {
    $data = ['title' => 'a', 'note' => 'keep'];

    AutosaveFieldTree::eachMatch(
        $data,
        ['title' => [1]],
        function (array &$data, array $fieldSet, string $match): void {
            unset($data[$match]);
        },
    );

    expect($data)->toBe(['note' => 'keep']);
});

test('eachMatch with no field patterns goes nowhere', function () {
    $visits = [];
    $data = ['a' => 1];

    AutosaveFieldTree::eachMatch($data, [], function () use (&$visits) {
        $visits[] = true;
    });

    expect($visits)->toBe([]);
});

test('forgetting a leaf also clears its emptied parents', function () {
    $data = ['a' => ['b' => 1]];
    AutosaveFieldTree::forget($data, 'a.b');

    expect($data)->toBe([]);
});

test('forgetting a leaf keeps sibling values intact', function () {
    $data = ['a' => ['b' => 1, 'c' => 2]];
    AutosaveFieldTree::forget($data, 'a.b');

    expect($data)->toBe(['a' => ['c' => 2]]);
});

test('forgetting a path that does not exist leaves the data alone', function () {
    $data = ['a' => 1];
    AutosaveFieldTree::forget($data, 'x.y');

    expect($data)->toBe(['a' => 1]);
});

test('forgetting a deep leaf cleans every emptied level', function () {
    $data = ['a' => ['b' => ['c' => 1]]];
    AutosaveFieldTree::forget($data, 'a.b.c');

    expect($data)->toBe([]);
});
