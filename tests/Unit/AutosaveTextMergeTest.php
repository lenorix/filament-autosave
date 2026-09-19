<?php

use Lenorix\FilamentAutosave\AutosaveApplyResult;
use Lenorix\FilamentAutosave\AutosaveMergeResult;
use Lenorix\FilamentAutosave\AutosaveTextMerge;

function mergeText(string $base, string $ours, string $theirs): AutosaveMergeResult
{
    return (new AutosaveTextMerge)->merge($base, $ours, $theirs);
}

test('identical inputs merge to themselves without hunks', function () {
    $result = mergeText('one two three', 'one two three', 'one two three');

    expect($result->value)->toBe('one two three')
        ->and($result->oursHunks)->toBe([])
        ->and($result->theirsHunks)->toBe([])
        ->and($result->conflicts)->toBe([]);
});

test('only ours changed keeps ours', function () {
    expect(mergeText('one two three', 'one TWO three', 'one two three')->value)->toBe('one TWO three');
});

test('only theirs changed keeps theirs', function () {
    expect(mergeText('one two three', 'one two three', 'one two THREE')->value)->toBe('one two THREE');
});

test('both sides making the same change merge cleanly', function () {
    $result = mergeText('one two three', 'one two four', 'one two four');

    expect($result->value)->toBe('one two four')
        ->and($result->conflicts)->toBe([]);
});

test('edits at the start and at the end of the same text are both kept', function () {
    $result = mergeText(
        'The quick brown fox jumps over the lazy dog',
        'A quick brown fox jumps over the lazy dog',
        'The quick brown fox jumps over the sleepy cat',
    );

    expect($result->value)->toBe('A quick brown fox jumps over the sleepy cat')
        ->and($result->conflicts)->toBe([])
        ->and($result->oursHunks)->toHaveCount(1)
        // "lazy" -> "sleepy" and "dog" -> "cat" are two words apart.
        ->and($result->theirsHunks)->toHaveCount(2);
});

test('ours inserting at the start and theirs appending at the end are both kept', function () {
    expect(mergeText('hello world', 'Well, hello world', 'hello world again')->value)
        ->toBe('Well, hello world again');
});

test('both sides appending different text keeps ours first then theirs', function () {
    $result = mergeText('hello', 'hello ours', 'hello theirs');

    expect($result->value)->toBe('hello ours theirs')
        ->and($result->conflicts)->toBe([]);
});

test('a deletion on one side and an edit elsewhere on the other are both applied', function () {
    expect(mergeText('a b c d e', 'a c d e', 'a b c d E')->value)->toBe('a c d E');
});

test('deleting the same words on both sides is not a conflict', function () {
    $result = mergeText('a b c d', 'a d', 'a d');

    expect($result->value)->toBe('a d')->and($result->conflicts)->toBe([]);
});

test('the same word edited differently on both sides is a conflict where ours wins', function () {
    $result = mergeText('one two three', 'one TWO three', 'one 2 three');

    expect($result->value)->toBe('one TWO three')
        ->and($result->conflicts)->toHaveCount(1)
        ->and($result->conflicts[0])->toMatchArray(['ours' => 'TWO', 'theirs' => '2', 'position' => 4]);
});

test('an overlapping hunk lets ours win only in that range and keeps the rest of theirs', function () {
    $result = mergeText(
        'alpha beta gamma delta epsilon',
        'alpha BETA gamma delta epsilon',
        'alpha beta2 gamma delta EPSILON',
    );

    expect($result->value)->toBe('alpha BETA gamma delta EPSILON')
        ->and($result->conflicts)->toHaveCount(1)
        ->and($result->conflicts[0]['ours'])->toBe('BETA')
        ->and($result->conflicts[0]['theirs'])->toBe('beta2');
});

test('a conflict reports the position in the merged value', function () {
    $result = mergeText('x y z', 'x Y z', 'x yy z');

    expect($result->conflicts[0]['position'])->toBe(2);
});

test('multiple non-overlapping hunks from both sides interleave in order', function () {
    expect(mergeText('a b c d e f g', 'A b c D e f g', 'a b C d e F g')->value)->toBe('A b C D e F g');
});

test('separators are preserved and are part of the diff', function () {
    expect(mergeText("one\ntwo\nthree", "one\n\ntwo\nthree", "one\ntwo\n\nthree")->value)->toBe("one\n\ntwo\n\nthree");
});

test('changing punctuation only on one side is kept', function () {
    expect(mergeText('hello world', 'hello, world', 'hello world!')->value)->toBe('hello, world!');
});

test('unicode words and emoji are treated as atoms', function () {
    expect(mergeText('mañana será 🚀 genial', 'mañana será 🚀 genial!', 'hoy será 🚀 genial')->value)
        ->toBe('hoy será 🚀 genial!');
});

test('an empty base treats both sides as insertions', function () {
    $result = mergeText('', 'ours', 'theirs');

    expect($result->value)->toBe('ours theirs')->and($result->conflicts)->toBe([]);
});

test('an empty base with only one side is that side', function () {
    expect(mergeText('', '', 'theirs')->value)->toBe('theirs')
        ->and(mergeText('', 'ours', '')->value)->toBe('ours');
});

test('ours clearing the field while theirs edits is a conflict where ours wins', function () {
    $result = mergeText('one two', '', 'one three');

    expect($result->value)->toBe('')->and($result->conflicts)->toHaveCount(1);
});

test('theirs clearing the field while ours is untouched clears the field', function () {
    expect(mergeText('one two', 'one two', '')->value)->toBe('');
});

test('merging is deterministic for the same inputs', function () {
    $first = mergeText('a b c d e f', 'a X c d e f', 'a b c d Y f');
    $second = mergeText('a b c d e f', 'a X c d e f', 'a b c d Y f');

    expect($first)->toEqual($second)->and($first->value)->toBe('a X c d Y f');
});

test('hunks are reported with their positions in the merged value', function () {
    $result = mergeText('a b c', 'A b c', 'a b C');

    expect($result->oursHunks)->toBe([['position' => 0, 'from' => 'a', 'to' => 'A']])
        ->and($result->theirsHunks)->toBe([['position' => 4, 'from' => 'c', 'to' => 'C']]);
});

test('a long paragraph with distant edits merges without conflict', function () {
    $words = [];
    for ($i = 0; $i < 400; $i++) {
        $words[] = 'w'.$i;
    }
    $base = implode(' ', $words);
    $ours = str_replace('w10 ', 'W10 ', $base);
    $theirs = str_replace('w390 ', 'W390 ', $base);

    $result = mergeText($base, $ours, $theirs);

    expect($result->conflicts)->toBe([])
        ->and($result->value)->toContain('W10 ')
        ->and($result->value)->toContain('W390 ');
});

// --- Patch application (diff-match-patch text format) ---------------------

function applyPatch(string $theirs, string $base, string $ours): AutosaveApplyResult
{
    $engine = new AutosaveTextMerge;

    return $engine->apply($theirs, $engine->makePatch($base, $ours));
}

test('a patch built from base and ours reproduces ours on an untouched record', function () {
    $result = applyPatch('one two three', 'one two three', 'one TWO three');

    expect($result->value)->toBe('one TWO three')
        ->and($result->applied)->toBe([true])
        ->and($result->conflicts)->toBe([]);
});

test('an empty patch leaves the text alone', function () {
    $result = (new AutosaveTextMerge)->apply('anything', '');

    expect($result->value)->toBe('anything')->and($result->applied)->toBe([]);
});

test('a patch text uses the diff-match-patch format', function () {
    $patch = (new AutosaveTextMerge)->makePatch('one two three', 'one TWO three');

    expect($patch)->toStartWith('@@ -')
        ->and($patch)->toContain("\n-two\n")
        ->and($patch)->toContain("\n+TWO\n");
});

test('a patch made with the reference diff-match-patch text applies', function () {
    // Produced by the JS library for: 'The quick brown fox' -> 'The quick red fox'
    $patch = "@@ -6,10 +6,8 @@\n uick \n-brown\n+red\n  fox\n";

    expect((new AutosaveTextMerge)->apply('The quick brown fox', $patch)->value)->toBe('The quick red fox');
});

test('a patch applies when the other editor changed text elsewhere', function () {
    $result = applyPatch('one two three FOUR five six', 'one two three four five six', 'ONE two three four five six');

    expect($result->value)->toBe('ONE two three FOUR five six')
        ->and($result->applied)->toBe([true])
        ->and($result->conflicts)->toBe([]);
});

test('a patch applies when the other editor shifted its position', function () {
    $base = 'alpha beta gamma delta';
    $result = applyPatch('A long new sentence was inserted here first. '.$base, $base, 'alpha beta gamma DELTA');

    expect($result->value)->toBe('A long new sentence was inserted here first. alpha beta gamma DELTA')
        ->and($result->applied)->toBe([true]);
});

test('a hunk whose text the other editor rewrote is a conflict where ours wins in that range', function () {
    $result = applyPatch('one 2 three', 'one two three', 'one TWO three');

    expect($result->value)->toBe('one TWO three')
        ->and($result->applied)->toBe([false])
        ->and($result->conflicts)->toHaveCount(1)
        ->and($result->conflicts[0])->toMatchArray(['ours' => 'TWO', 'theirs' => '2', 'position' => 4]);
});

test('a conflicting hunk keeps the other editor\'s changes outside its range', function () {
    $result = applyPatch(
        'alpha beta2 gamma delta EPSILON',
        'alpha beta gamma delta epsilon',
        'alpha BETA gamma delta epsilon',
    );

    expect($result->value)->toBe('alpha BETA gamma delta EPSILON')
        ->and($result->conflicts[0])->toMatchArray(['ours' => 'BETA', 'theirs' => 'beta2']);
});

test('several hunks apply independently and report per hunk', function () {
    $base = 'w0 w1 w2 w3 w4 w5 w6 w7 w8 w9 w10 w11 w12 w13 w14 w15 w16 w17 w18 w19';
    $ours = str_replace(['w0 ', ' w19'], ['W0 ', ' W19'], $base);
    $theirs = str_replace(' w19', ' X19', $base);

    $result = applyPatch($theirs, $base, $ours);

    expect($result->applied)->toBe([true, false])
        ->and($result->value)->toBe($ours)
        ->and($result->conflicts)->toHaveCount(1);
});

test('patches round-trip unicode and newlines', function () {
    $base = "mañana\nserá 🚀 genial";
    $ours = "mañana\n\nserá 🚀 GENIAL";

    expect(applyPatch($base, $base, $ours)->value)->toBe($ours);
});

test('a patch against an empty base inserts on an empty record', function () {
    expect(applyPatch('', '', 'brand new')->value)->toBe('brand new');
});

test('a patch that deletes everything empties the field', function () {
    expect(applyPatch('one two', 'one two', '')->value)->toBe('');
});

test('a patch applies on a long text with edits far from each other', function () {
    $words = [];
    for ($i = 0; $i < 3000; $i++) {
        $words[] = 'palabra'.$i;
    }
    $base = implode(' ', $words);
    $ours = str_replace('palabra2990 ', 'PALABRA2990 ', $base);
    $theirs = str_replace('palabra5 ', 'PALABRA5 ', $base);

    $result = applyPatch($theirs, $base, $ours);

    expect($result->applied)->toBe([true])
        ->and($result->value)->toContain('PALABRA5 ')
        ->and($result->value)->toContain('PALABRA2990 ');
});

test('a malformed patch is rejected', function () {
    expect(fn () => (new AutosaveTextMerge)->apply('text', "not a patch\n"))->toThrow(InvalidArgumentException::class);
});

test('a value that is not valid UTF-8 is rejected instead of being read as empty', function () {
    $merge = new AutosaveTextMerge;

    expect(fn () => $merge->merge('cafe au lait', 'cafe au lait!', "caf\xe9 au lait"))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $merge->merge("caf\xe9", 'cafe', 'cafe'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $merge->apply("caf\xe9 au lait", $merge->makePatch('a', 'b')))->toThrow(InvalidArgumentException::class);
});

test('a patch whose escapes decode to invalid UTF-8 is rejected as malformed', function () {
    expect(fn () => (new AutosaveTextMerge)->apply('text', "@@ -1,4 +1,4 @@\n-%E0%A4%A\n+text\n"))
        ->toThrow(InvalidArgumentException::class);
});

test('a fuzzy-matched replacement lands its insertion where the deletion was', function () {
    $merge = new AutosaveTextMerge;
    $base = 'alpha beta gamma delta epsilon zeta';
    $ours = 'alpha beta GAMMA delta epsilon zeta';
    // The other editor touched the hunk's context, so it only matches fuzzily.
    $theirs = 'alpha betA gamma deltA epsilon zeta';

    $result = $merge->apply($theirs, $merge->makePatch($base, $ours));

    expect($result->value)->toBe('alpha betA GAMMA deltA epsilon zeta')
        ->and($result->applied)->toBe([true])
        ->and($result->conflicts)->toBe([]);
});

test('a context-less patch never matches the synthetic padding', function () {
    $merge = new AutosaveTextMerge;
    $result = $merge->apply('hello world abc', $merge->makePatch('abc', 'xyz'));

    expect($result->value)->not->toContain("\x01")
        ->and($result->value)->toBe('hello world xyz');
});
