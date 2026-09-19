<?php

use Illuminate\Support\Facades\Cache;
use Lenorix\FilamentAutosave\AutosaveUndo;

beforeEach(fn () => Cache::flush());

test('an edit page keeps its column snapshot at the bare base key and suffixes the rest', function () {
    $undo = new AutosaveUndo('k', 5, bareValuesKey: true);

    expect($undo->key(AutosaveUndo::VALUES))->toBe('k')
        ->and($undo->key(AutosaveUndo::EXPECTED))->toBe('k:expected')
        ->and($undo->keys())->toBe([
            'k', 'k:relationships', 'k:expected', 'k:expected-relationships', 'k:external', 'k:expected-external',
        ]);
});

test('a generic form suffixes every part including the column snapshot', function () {
    $undo = new AutosaveUndo('k', 5);

    expect($undo->key(AutosaveUndo::VALUES))->toBe('k:values')
        ->and($undo->keys())->not->toContain('k');
});

test('put stores non-empty parts for the ttl and reports whether it stored', function () {
    $undo = new AutosaveUndo('k', 5);

    expect($undo->put(AutosaveUndo::VALUES, []))->toBeFalse()
        ->and($undo->get(AutosaveUndo::VALUES))->toBeNull()
        ->and($undo->put(AutosaveUndo::VALUES, ['title' => 'a']))->toBeTrue()
        ->and($undo->get(AutosaveUndo::VALUES))->toBe(['title' => 'a']);

    $this->travel(6)->minutes();

    expect($undo->get(AutosaveUndo::VALUES))->toBeNull();
});

test('get ignores cache entries that are not arrays', function () {
    $undo = new AutosaveUndo('k', 5);
    Cache::put('k:values', 'garbage');

    expect($undo->get(AutosaveUndo::VALUES))->toBeNull();
});

test('hasSnapshot looks only at the before parts and clear removes every part', function () {
    $undo = new AutosaveUndo('k', 5);

    expect($undo->hasSnapshot())->toBeFalse();

    $undo->put(AutosaveUndo::EXPECTED, ['title' => 'a']);
    expect($undo->hasSnapshot())->toBeFalse();

    $undo->put(AutosaveUndo::RELATIONSHIPS, ['items' => ['type' => 'hasOneOrMany', 'rows' => []]]);
    expect($undo->hasSnapshot())->toBeTrue();

    $undo->clear();

    foreach (AutosaveUndo::PARTS as $part) {
        expect(Cache::has($undo->key($part)))->toBeFalse();
    }
});

test('columns match only when the record still holds what the autosave wrote', function () {
    expect(AutosaveUndo::columnsMatch(null, ['title' => 'x']))->toBeTrue()
        ->and(AutosaveUndo::columnsMatch(['title' => 'a'], ['title' => 'a']))->toBeTrue()
        ->and(AutosaveUndo::columnsMatch(['title' => 'a'], ['title' => 'b']))->toBeFalse();
});

test('relationships match on the touched paths only and treat a vanished path as a conflict', function () {
    $expected = ['items' => ['type' => 'hasOneOrMany', 'rows' => [['attributes' => ['id' => 1]]]]];

    expect(AutosaveUndo::relationshipsMatch(null, []))->toBeTrue()
        ->and(AutosaveUndo::relationshipsMatch($expected, $expected + ['authors' => ['type' => 'belongsToMany', 'rows' => []]]))->toBeTrue()
        ->and(AutosaveUndo::relationshipsMatch($expected, ['items' => ['type' => 'hasOneOrMany', 'rows' => []]]))->toBeFalse()
        ->and(AutosaveUndo::relationshipsMatch($expected, []))->toBeFalse();
});
