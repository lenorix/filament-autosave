<?php

use Lenorix\FilamentAutosave\AutosaveRichMerge;
use Lenorix\FilamentAutosave\AutosaveRichMergeResult;
use Lenorix\FilamentAutosave\Tests\Fixtures\RichMergeEditor;

/**
 * Builds the engine for a column format. Inputs are written as HTML for
 * readability and converted to the column format first, so every scenario
 * runs once per format.
 */
function richMerge(string $format): AutosaveRichMerge
{
    return new AutosaveRichMerge(RichMergeEditor::make(), json: $format === 'json');
}

function richValue(string $format, ?string $html): string|array|null
{
    if ($html === null) {
        return null;
    }

    return $format === 'json' ? RichMergeEditor::make()->setContent($html)->getDocument() : $html;
}

function mergeRich(string $format, ?string $base, ?string $ours, ?string $theirs): AutosaveRichMergeResult
{
    return richMerge($format)->merge(richValue($format, $base), richValue($format, $ours), richValue($format, $theirs));
}

function richHtml(string|array|null $value): string
{
    if ($value === null || $value === '' || $value === []) {
        $value = ['type' => 'doc', 'content' => []];
    } elseif (is_array($value) && array_is_list($value)) {
        $value = ['type' => 'doc', 'content' => $value];
    }

    return RichMergeEditor::make()->setContent($value)->getHTML();
}

$formats = ['html', 'json'];

// ---------------------------------------------------------------------------
// Canonical form
// ---------------------------------------------------------------------------

test('canonical output matches what Tiptap itself serialises', function (string $format) {
    $engine = richMerge($format);
    $messy = '<p>a "q" <a href="https://x.io">l</a></p>';

    $canonical = $engine->canonical($messy);

    // HTML columns get Tiptap's serialisation; JSON columns the parsed document.
    expect($canonical)->toBe($format === 'json' ? richValue('json', $messy) : richHtml($messy))
        ->and($engine->isCanonical($canonical))->toBeTrue()
        // A JSON column holding HTML, or HTML that Tiptap would rewrite, is not canonical.
        ->and($engine->isCanonical($messy))->toBeFalse();
})->with($formats);

test('blank inputs are an empty document', function (string $format) {
    $engine = richMerge($format);

    expect(richHtml($engine->canonical(null)))->toBe('')
        ->and(richHtml($engine->canonical('')))->toBe('')
        ->and($engine->attachmentIds(null))->toBe([]);

    $result = $engine->merge(null, richValue($format, '<p>new</p>'), null);

    expect(richHtml($result->value))->toBe('<p>new</p>')
        ->and($result->conflicts)->toBe([]);
})->with($formats);

test('a no-op merge returns the canonical current value with no conflicts', function (string $format) {
    $current = '<p>Same <strong>text</strong></p><ul><li><p>one</p></li></ul>';
    $result = mergeRich($format, $current, $current, $current);

    expect($result->value)->toBe(richMerge($format)->canonical(richValue($format, $current)))
        ->and($result->conflicts)->toBe([])
        ->and($result->changed)->toBeFalse();
})->with($formats);

test('identical changes on both sides are not a conflict', function (string $format) {
    $result = mergeRich($format, '<p>one two</p>', '<p>one three</p><p>added</p>', '<p>one three</p><p>added</p>');

    expect(richHtml($result->value))->toBe('<p>one three</p><p>added</p>')
        ->and($result->conflicts)->toBe([]);
})->with($formats);

// ---------------------------------------------------------------------------
// Block level
// ---------------------------------------------------------------------------

test('blocks inserted, deleted and edited on different sides are all kept', function (string $format) {
    $base = '<p>first</p><p>second</p><p>third</p>';
    $ours = '<p>first</p><p>third</p><p>ours added</p>';
    $theirs = '<h2>theirs added</h2><p>first</p><p>second edited</p><p>third</p>';

    $result = mergeRich($format, $base, $ours, $theirs);

    // "second" was deleted by ours and edited by theirs: ours wins, theirs is reported.
    expect(richHtml($result->value))->toBe('<h2>theirs added</h2><p>first</p><p>third</p><p>ours added</p>')
        ->and($result->conflicts)->toHaveCount(1)
        ->and($result->conflicts[0]['reason'])->toBe('overlap')
        ->and($result->conflicts[0]['kind'])->toBe('block')
        ->and(richHtml($result->conflicts[0]['theirs']))->toBe('<p>second edited</p>')
        ->and(richHtml($result->conflicts[0]['ours']))->toBe('');
})->with($formats);

test('a block deleted on one side and untouched on the other is deleted', function (string $format) {
    $result = mergeRich($format, '<p>a</p><p>b</p><p>c</p>', '<p>a</p><p>c</p>', '<p>a</p><p>b</p><p>c edited</p>');

    expect(richHtml($result->value))->toBe('<p>a</p><p>c edited</p>')
        ->and($result->conflicts)->toBe([]);
})->with($formats);

test('blocks inserted at the same point by both sides are both kept, ours first', function (string $format) {
    $result = mergeRich($format, '<p>a</p><p>z</p>', '<p>a</p><p>ours</p><p>z</p>', '<p>a</p><p>theirs</p><p>z</p>');

    expect(richHtml($result->value))->toBe('<p>a</p><p>ours</p><p>theirs</p><p>z</p>')
        ->and($result->conflicts)->toBe([]);
})->with($formats);

test('blocks are matched by content similarity, never by index alone', function (string $format) {
    // Ours inserts a paragraph at the top; theirs edits the (now shifted) last one.
    $result = mergeRich(
        $format,
        '<p>alpha beta gamma</p><p>delta epsilon zeta</p>',
        '<p>intro</p><p>alpha beta gamma</p><p>delta epsilon zeta</p>',
        '<p>alpha beta gamma</p><p>delta epsilon ZETA</p>',
    );

    expect(richHtml($result->value))->toBe('<p>intro</p><p>alpha beta gamma</p><p>delta epsilon ZETA</p>')
        ->and($result->conflicts)->toBe([]);
})->with($formats);

test('a block moved by one side and edited by the other is moved with the edit', function (string $format) {
    $result = mergeRich(
        $format,
        '<p>alpha beta gamma</p><p>delta epsilon zeta</p><p>eta theta iota</p>',
        '<p>delta epsilon zeta</p><p>alpha beta gamma</p><p>eta theta iota</p>',
        '<p>alpha beta gamma</p><p>delta epsilon ZETA</p><p>eta theta iota</p>',
    );

    expect(richHtml($result->value))->toBe('<p>delta epsilon ZETA</p><p>alpha beta gamma</p><p>eta theta iota</p>')
        ->and($result->conflicts)->toBe([]);
})->with($formats);

test('nested containers merge recursively: list items, blockquotes, details and grid columns', function (string $format) {
    $base = '<ul><li><p>one</p></li><li><p>two</p></li></ul><blockquote><p>quote</p></blockquote><details><summary>sum</summary><div data-type="detailsContent"><p>body</p></div></details>';
    $ours = '<ul><li><p>one</p></li><li><p>two</p></li><li><p>three</p></li></ul><blockquote><p>quote</p></blockquote><details><summary>sum</summary><div data-type="detailsContent"><p>body</p></div></details>';
    $theirs = '<ul><li><p>ONE</p></li><li><p>two</p></li></ul><blockquote><p>quote changed</p></blockquote><details><summary>summary</summary><div data-type="detailsContent"><p>body</p></div></details>';

    $result = mergeRich($format, $base, $ours, $theirs);

    expect(richHtml($result->value))->toBe('<ul><li><p>ONE</p></li><li><p>two</p></li><li><p>three</p></li></ul><blockquote><p>quote changed</p></blockquote><details><summary>summary</summary><div data-type="detailsContent"><p>body</p></div></details>')
        ->and($result->conflicts)->toBe([]);
})->with($formats);

test('headings and code blocks merge; code text is one token stream, never split by words', function (string $format) {
    $base = '<h1>Title</h1><pre><code>let a = 1;\nlet b = 2;</code></pre>';
    $ours = '<h1>Title!</h1><pre><code>let a = 1;\nlet b = 2;</code></pre>';
    $theirs = '<h1>Title</h1><pre><code>let a = 10;\nlet b = 2;</code></pre>';

    $result = mergeRich($format, $base, $ours, $theirs);

    expect(richHtml($result->value))->toBe('<h1>Title!</h1><pre><code>let a = 10;\nlet b = 2;</code></pre>')
        ->and($result->conflicts)->toBe([]);

    // Both edit the code block: whole block is one overlap, ours wins.
    $both = mergeRich($format, $base, '<h1>Title</h1><pre><code>let a = 1;\nlet b = 3;</code></pre>', $theirs);

    expect(richHtml($both->value))->toBe('<h1>Title</h1><pre><code>let a = 1;\nlet b = 3;</code></pre>')
        ->and($both->conflicts)->toHaveCount(1);
})->with($formats);

// ---------------------------------------------------------------------------
// Inline level
// ---------------------------------------------------------------------------

test('words changed at different places of one paragraph are both kept', function (string $format) {
    $result = mergeRich(
        $format,
        '<p>The quick brown fox jumps over the lazy dog</p>',
        '<p>A quick brown fox jumps over the lazy dog</p>',
        '<p>The quick brown fox jumps over the sleepy cat</p>',
    );

    expect(richHtml($result->value))->toBe('<p>A quick brown fox jumps over the sleepy cat</p>')
        ->and($result->conflicts)->toBe([]);
})->with($formats);

test('a mark applied by one side and text kept by the other merge cleanly', function (string $format) {
    $result = mergeRich(
        $format,
        '<p>one two three four</p>',
        '<p>one <strong>two</strong> three four</p>',
        '<p>one two three FOUR</p>',
    );

    expect(richHtml($result->value))->toBe('<p>one <strong>two</strong> three FOUR</p>')
        ->and($result->conflicts)->toBe([]);
})->with($formats);

test('a mark change and a text change on the same words overlap, ours wins and theirs is reported with a position', function (string $format) {
    $result = mergeRich(
        $format,
        '<p>intro</p><p>one two three</p>',
        '<p>intro</p><p>one TWO three</p>',
        '<p>intro</p><p>one <em>two</em> three</p>',
    );

    expect(richHtml($result->value))->toBe('<p>intro</p><p>one TWO three</p>')
        ->and($result->conflicts)->toHaveCount(1);

    $conflict = $result->conflicts[0];

    expect($conflict['reason'])->toBe('overlap')
        ->and($conflict['kind'])->toBe('inline')
        ->and($conflict['block'])->toBe([1])
        ->and($conflict['position'])->toBe(mb_strlen("intro\none "))
        ->and(richHtml($conflict['ours']))->toBe('<p>TWO</p>')
        ->and(richHtml($conflict['theirs']))->toBe('<p><em>two</em></p>');
})->with($formats);

test('links and colours are marks that travel with their words', function (string $format) {
    $result = mergeRich(
        $format,
        '<p>read the docs now</p>',
        '<p>read the <a href="https://x.io">docs</a> now</p>',
        '<p>read the docs <span data-color="danger" style="color: red">now</span></p>',
    );

    expect(richHtml($result->value))->toBe(richHtml('<p>read the <a href="https://x.io">docs</a> <span data-color="danger" style="color: red">now</span></p>'))
        ->and($result->conflicts)->toBe([]);
})->with($formats);

test('accented text and emoji merge on code points', function (string $format) {
    $result = mergeRich(
        $format,
        '<p>café 😀 niño</p>',
        '<p>café 😀 niña</p>',
        '<p>CAFÉ 😀 niño</p>',
    );

    expect(richHtml($result->value))->toBe('<p>CAFÉ 😀 niña</p>')
        ->and($result->conflicts)->toBe([]);
})->with($formats);

test('inline atoms — mentions, merge tags and hard breaks — are single opaque tokens', function (string $format) {
    $base = '<p>hi <span data-type="mention" data-id="u1" data-char="@"></span> and <span data-type="mergeTag" data-id="name"></span> bye<br>end</p>';
    $ours = '<p>hi <span data-type="mention" data-id="u2" data-char="@"></span> and <span data-type="mergeTag" data-id="name"></span> bye<br>end</p>';
    $theirs = '<p>hi <span data-type="mention" data-id="u1" data-char="@"></span> and <span data-type="mergeTag" data-id="name"></span> goodbye<br>END</p>';

    $result = mergeRich($format, $base, $ours, $theirs);

    expect(richHtml($result->value))->toBe(richHtml('<p>hi <span data-type="mention" data-id="u2" data-char="@"></span> and <span data-type="mergeTag" data-id="name"></span> goodbye<br>END</p>'))
        ->and($result->conflicts)->toBe([]);
})->with($formats);

// ---------------------------------------------------------------------------
// Atomic block nodes
// ---------------------------------------------------------------------------

test('images are matched by id: one side moves it, the other edits its alt', function (string $format) {
    $base = '<p>a</p><img src="/a.png" alt="A" data-id="att/a.png"><p>b</p>';
    $ours = '<img src="/a.png" alt="A" data-id="att/a.png"><p>a</p><p>b</p>';
    $theirs = '<p>a</p><img src="/a.png" alt="Better" data-id="att/a.png"><p>b</p>';

    $result = mergeRich($format, $base, $ours, $theirs);

    expect(richHtml($result->value))->toBe('<img src="/a.png" alt="Better" data-id="att/a.png"><p>a</p><p>b</p>')
        ->and($result->conflicts)->toBe([]);
})->with($formats);

test('an image deleted by one side and edited by the other is an overlap; ours wins', function (string $format) {
    $base = '<p>a</p><img src="/a.png" alt="A" data-id="att/a.png">';
    $result = mergeRich($format, $base, '<p>a</p>', '<p>a</p><img src="/a.png" alt="Edited" data-id="att/a.png">');

    expect(richHtml($result->value))->toBe('<p>a</p>')
        ->and($result->conflicts)->toHaveCount(1)
        ->and($result->conflicts[0]['kind'])->toBe('block')
        ->and(richHtml($result->conflicts[0]['theirs']))->toBe('<img src="/a.png" alt="Edited" data-id="att/a.png">');

    $reverse = mergeRich($format, $base, '<p>a</p><img src="/a.png" alt="Edited" data-id="att/a.png">', '<p>a</p>');

    expect(richHtml($reverse->value))->toBe('<p>a</p><img src="/a.png" alt="Edited" data-id="att/a.png">')
        ->and($reverse->conflicts)->toHaveCount(1)
        ->and(richHtml($reverse->conflicts[0]['theirs']))->toBe('');
})->with($formats);

test('custom blocks are whole-node changes matched by id, config edits included', function (string $format) {
    $base = '<p>a</p><div data-type="customBlock" data-id="cta" data-config="{&quot;label&quot;:&quot;Buy&quot;}"></div>';
    $ours = '<p>a edited</p><div data-type="customBlock" data-id="cta" data-config="{&quot;label&quot;:&quot;Buy&quot;}"></div>';
    $theirs = '<p>a</p><div data-type="customBlock" data-id="cta" data-config="{&quot;label&quot;:&quot;Buy now&quot;}"></div>';

    $result = mergeRich($format, $base, $ours, $theirs);

    expect(richHtml($result->value))->toBe(richHtml('<p>a edited</p><div data-type="customBlock" data-id="cta" data-config="{&quot;label&quot;:&quot;Buy now&quot;}"></div>'))
        ->and($result->conflicts)->toBe([]);

    $both = mergeRich(
        $format,
        $base,
        '<p>a</p><div data-type="customBlock" data-id="cta" data-config="{&quot;label&quot;:&quot;Ours&quot;}"></div>',
        '<p>a</p><div data-type="customBlock" data-id="cta" data-config="{&quot;label&quot;:&quot;Theirs&quot;}"></div>',
    );

    expect(richHtml($both->value))->toContain('Ours')
        ->and($both->conflicts)->toHaveCount(1)
        ->and(richHtml($both->conflicts[0]['theirs']))->toContain('Theirs');
})->with($formats);

test('a horizontal rule and two images with distinct ids added by each side are all kept', function (string $format) {
    $result = mergeRich(
        $format,
        '<p>a</p>',
        '<p>a</p><img src="/o.png" alt="o" data-id="att/o.png">',
        '<hr><p>a</p><img src="/t.png" alt="t" data-id="att/t.png">',
    );

    expect(richHtml($result->value))->toBe('<hr><p>a</p><img src="/o.png" alt="o" data-id="att/o.png"><img src="/t.png" alt="t" data-id="att/t.png">')
        ->and($result->conflicts)->toBe([]);
})->with($formats);

// ---------------------------------------------------------------------------
// Attachments and projection
// ---------------------------------------------------------------------------

test('attachment ids list the image ids of a value', function (string $format) {
    $value = richValue($format, '<p>x</p><img src="/a.png" data-id="att/a.png"><img src="https://ext/b.png"><ul><li><img src="/c.png" data-id="att/c.png"></li></ul>');

    expect(richMerge($format)->attachmentIds($value))->toBe(['att/a.png', 'att/c.png']);
})->with($formats);

test('an image kept by one side survives the merge even when the other side dropped it, so its file must not be deleted', function (string $format) {
    $base = '<p>a</p><img src="/a.png" alt="A" data-id="att/a.png"><p>b</p>';
    $ours = '<p>a</p><p>b</p>';
    $theirs = '<p>a</p><img src="/a.png" alt="A" data-id="att/a.png"><p>b edited</p>';
    $engine = richMerge($format);

    $result = $engine->merge(richValue($format, $base), richValue($format, $ours), richValue($format, $theirs));

    // Ours deleted, theirs untouched: the image goes.
    expect($engine->attachmentIds($result->value))->toBe([]);

    $kept = $engine->merge(richValue($format, $base), richValue($format, $theirs), richValue($format, $ours));

    // Ours (theirs above) edited the neighbour and kept the image; theirs deleted it: deletion applies, nothing conflicts.
    expect($engine->attachmentIds($kept->value))->toBe([]);

    $edited = $engine->merge(richValue($format, $base), richValue($format, '<p>a</p><img src="/a.png" alt="New" data-id="att/a.png"><p>b</p>'), richValue($format, $ours));

    expect($engine->attachmentIds($edited->value))->toBe(['att/a.png'])
        ->and(array_diff($engine->attachmentIds(richValue($format, $base)), $engine->attachmentIds($edited->value)))->toBe([]);
})->with($formats);

test('a private image whose src was nulled by the state cast is not a change', function (string $format) {
    $stored = '<p>a</p><img src="/a.png" alt="A" data-id="att/a.png">';
    $projected = '<p>a</p><img alt="A" data-id="att/a.png">';

    $result = mergeRich($format, $stored, $projected, '<p>a changed</p><img src="/a.png" alt="A" data-id="att/a.png">');

    expect($result->conflicts)->toBe([])
        ->and(richHtml($result->value))->toBe('<p>a changed</p><img src="/a.png" alt="A" data-id="att/a.png">');

    $sameAlt = mergeRich($format, $stored, '<p>a</p><img alt="Edited" data-id="att/a.png">', $stored);

    expect($sameAlt->conflicts)->toBe([])
        ->and(richHtml($sameAlt->value))->toBe('<p>a</p><img src="/a.png" alt="Edited" data-id="att/a.png">');
})->with($formats);

test('plain text positions count block texts joined by newlines', function (string $format) {
    $engine = richMerge($format);

    expect($engine->plainText(richValue($format, '<h1>Ti</h1><p>a<br>b</p><ul><li><p>x</p></li></ul><img data-id="i">')))->toBe("Ti\na\nb\nx\n");
})->with($formats);

// ---------------------------------------------------------------------------
// More structure
// ---------------------------------------------------------------------------

test('grid columns merge independently', function (string $format) {
    $grid = static fn (string $left, string $right): string => '<div class="grid-layout" data-cols="2"><div class="grid-col" data-col-span="1"><p>'.$left.'</p></div><div class="grid-col" data-col-span="1"><p>'.$right.'</p></div></div>';

    $result = mergeRich($format, $grid('left', 'right'), $grid('left edited', 'right'), $grid('left', 'right edited'));

    expect(richHtml($result->value))->toBe(richHtml($grid('left edited', 'right edited')))
        ->and($result->conflicts)->toBe([]);
})->with($formats);

test('turning a paragraph into a heading keeps the other side\'s word edit', function (string $format) {
    $result = mergeRich($format, '<p>section title here</p>', '<h2>section title here</h2>', '<p>section title HERE</p>');

    expect(richHtml($result->value))->toBe('<h2>section title HERE</h2>')
        ->and($result->conflicts)->toBe([]);
})->with($formats);

test('a list item deleted by one side and edited by the other is reported at its path', function (string $format) {
    $result = mergeRich(
        $format,
        '<p>intro</p><ul><li><p>one two</p></li><li><p>three four</p></li></ul>',
        '<p>intro</p><ul><li><p>one two</p></li></ul>',
        '<p>intro</p><ul><li><p>one two</p></li><li><p>three FOUR</p></li></ul>',
    );

    expect(richHtml($result->value))->toBe('<p>intro</p><ul><li><p>one two</p></li></ul>')
        ->and($result->conflicts)->toHaveCount(1)
        ->and($result->conflicts[0]['block'])->toBe([1, 1])
        ->and($result->conflicts[0]['position'])->toBe(mb_strlen("intro\none two"))
        ->and(richHtml($result->conflicts[0]['theirs']))->toBe('<li><p>three FOUR</p></li>');
})->with($formats);

test('empty paragraphs and whitespace-only edits are handled', function (string $format) {
    $result = mergeRich($format, '<p></p><p>a</p>', '<p>x</p><p>a</p>', '<p></p><p>a</p><p></p>');

    expect(richHtml($result->value))->toBe('<p>x</p><p>a</p><p></p>')
        ->and($result->conflicts)->toBe([])
        ->and($result->changed)->toBeTrue();
})->with($formats);

test('a JSON document carrying a null image src (private visibility) merges against the stored one', function () {
    $stored = richValue('json', '<p>a</p><img src="/a.png" alt="A" data-id="att/a.png">');
    $projected = $stored;
    $projected['content'][1]['attrs']['src'] = null;
    $projected['content'][0]['content'][0]['text'] = 'a edited';

    $result = richMerge('json')->merge($stored, $projected, $stored);

    expect($result->conflicts)->toBe([])
        ->and($result->value['content'][1]['attrs']['src'])->toBe('/a.png')
        ->and($result->value['content'][0]['content'][0]['text'])->toBe('a edited');
});

test('two insertions of words at the same point are both kept, ours first, without a conflict', function (string $format) {
    $result = mergeRich($format, '<p>start end</p>', '<p>start ours end</p>', '<p>start theirs end</p>');

    expect(richHtml($result->value))->toBe('<p>start ours theirs end</p>')
        ->and($result->conflicts)->toBe([]);
})->with($formats);

test('a long document with many blocks merges in reasonable time', function (string $format) {
    $blocks = implode('', array_map(static fn (int $i): string => "<p>paragraph number {$i} with some words in it</p>", range(1, 200)));
    $ours = str_replace('number 10 ', 'number TEN ', $blocks);
    $theirs = str_replace('number 190 ', 'number ONE-NINETY ', $blocks);
    $started = microtime(true);

    $result = mergeRich($format, $blocks, $ours, $theirs);

    expect(microtime(true) - $started)->toBeLessThan(2.0)
        ->and(richHtml($result->value))->toContain('number TEN ')->toContain('number ONE-NINETY ')
        ->and($result->conflicts)->toBe([]);
})->with($formats);
