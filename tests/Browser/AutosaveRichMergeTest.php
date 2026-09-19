<?php

use Illuminate\Support\Facades\Storage;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\RichJsonPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Browser\BrowserRichMergePostResource;
use Lenorix\FilamentAutosave\Tests\Fixtures\RichMergeEditor;

/**
 * Two browsers on the same record, each typing in the same RichEditor,
 * with nothing but polling between them. The body is listed as mergeable
 * on `BrowserRichMergeEditPost` (HTML column) and its JSON twin; the
 * server merges the documents structurally and the browser applies the
 * result inside the live editor, around the caret.
 */
const RICH_EDITOR = 'Alpine.$data(document.querySelector("[x-data^=richEditorFormComponent]")).getEditor()';

/** The record and edit URL for one column format. */
function editorPost(string $format, string $html): array
{
    if ($format === 'json') {
        $post = RichJsonPost::create(['title' => 'Original', 'body' => editorDoc($html)]);

        return [$post, "/admin/rich-json-merge-posts/{$post->getKey()}/edit"];
    }

    $post = Post::create(['title' => 'Original', 'body' => $html]);

    return [$post, "/admin/rich-merge-posts/{$post->getKey()}/edit"];
}

/** Tiptap JSON for HTML, through the same editor the resource uses. */
function editorDoc(string $html): array
{
    return RichMergeEditor::make()->setContent($html)->getDocument();
}

/** The stored body as HTML, whichever column format. */
function editorStored(object $post): string
{
    $body = $post->fresh()->body;

    return is_array($body)
        ? RichMergeEditor::make()->setContent($body)->getHTML()
        : (string) $body;
}

/** The editor's document as the server would store it (HTML), for comparing with the column. */
function editorHtml(object $page): string
{
    return RichMergeEditor::make()->setContent((array) $page->script(RICH_EDITOR.'.getJSON()'))->getHTML();
}

function editorText(object $page): string
{
    return (string) $page->script(RICH_EDITOR.'.getText()');
}

/** [from, to] of a text within the document, as ProseMirror positions. */
function editorRangeScript(string $search): string
{
    return '(() => { const editor = '.RICH_EDITOR.'; let range = null; editor.state.doc.descendants((node, pos) => {'
        .' if (range || !node.isText) return; const i = node.text.indexOf('.json_encode($search).'); if (i >= 0) range = { from: pos + i, to: pos + i + '.mb_strlen($search).' } });'
        .' return range })()';
}

/** Replace a text in the editor as the user would: focus, select, type. */
function editorReplace(object $page, string $search, string $replacement): void
{
    $found = $page->script('(() => { const range = '.editorRangeScript($search).'; if (!range) return false; '
        .RICH_EDITOR.'.chain().focus().insertContentAt(range, '.json_encode($replacement).').run(); return true })()');

    if (! $found) {
        throw new RuntimeException("Text [{$search}] not found in the editor.");
    }
}

/** Put the caret right after a text. */
function editorCaretAfter(object $page, string $search): void
{
    $page->script('(() => { const range = '.editorRangeScript($search).'; '.RICH_EDITOR.'.chain().focus().setTextSelection(range.to).run(); return true })()');
}

function editorCaret(object $page): int
{
    return (int) $page->script(RICH_EDITOR.'.state.selection.from');
}

/** JavaScript expression: the rich editor is on the page and ready. */
function editorReady(): string
{
    return '(() => { try { return typeof FilamentAutosaveRichMerge === "object" && '.RICH_EDITOR.' != null } catch (e) { return false } })()';
}

/** Wait until the editor's HTML contains every fragment, yielding to the server meanwhile. */
function waitForEditorHtml(object $page, array $fragments, int $timeoutMs = 10_000): void
{
    $deadline = hrtime(true) + $timeoutMs * 1_000_000;

    do {
        $html = editorHtml($page);
        $missing = array_filter($fragments, fn (string $f): bool => ! str_contains($html, $f));

        if ($missing === []) {
            return;
        }

        usleep(100_000);
    } while (hrtime(true) < $deadline);

    throw new RuntimeException('Timed out waiting for the editor to contain '.json_encode(array_values($missing)).', got '.json_encode($html));
}

beforeEach(function () {
    config(['filament-autosave.poll_interval' => 60_000]);
});

dataset('rich columns', ['html' => ['html'], 'json' => ['json']]);

test('the rich merge runtime rides with the text one, once, and finds the editor', function () {
    [, $url] = editorPost('html', '<p>alpha beta gamma</p>');
    $page = visit($url);

    $this->waitUntil($page, editorReady(), 'rich editor');

    expect($page->script('document.querySelectorAll("script[data-autosave-rich-merge]").length'))->toBe(1)
        ->and($page->script('typeof FilamentAutosaveRichMerge.find'))->toBe('function')
        ->and($page->script('FilamentAutosaveRichMerge.find(document, "data.body") !== null'))->toBeTrue()
        ->and($page->script('FilamentAutosaveRichMerge.find(document, "data.title")'))->toBeNull()
        ->and($page->script('Alpine.$data(document.querySelector("[data-autosave-status]")).isRichField("body")'))->toBeTrue()
        ->and($page->script('Alpine.$data(document.querySelector("[data-autosave-status]")).isRichField("title")'))->toBeFalse();

    // A document from HTML and the same document from JSON are one document.
    expect($page->script('FilamentAutosaveRichMerge.docs.normalize('.RICH_EDITOR.', "<p>a <strong>b</strong></p>") === FilamentAutosaveRichMerge.docs.normalize('.RICH_EDITOR.', {type: "doc", content: [{type: "paragraph", content: [{type: "text", text: "a "}, {type: "text", text: "b", marks: [{type: "bold"}]}]}]})'))->toBeTrue()
        ->and($page->script('FilamentAutosaveRichMerge.docs.plainText(FilamentAutosaveRichMerge.docs.toDoc('.RICH_EDITOR.', "<p>one<br>two</p><p>three</p>"))'))->toBe("one\ntwo\nthree");
    $this->assertNoBrowserErrors($page);
});

test('two editors changing different paragraphs both end with the merged document', function (string $format) {
    [$post, $url] = editorPost($format, '<p>alpha beta gamma</p><p>one two three</p>');

    $one = visit($url);
    $two = visit($url);
    $this->waitUntil($one, editorReady(), 'rich editor');
    $this->waitUntil($two, editorReady(), 'rich editor');

    editorReplace($one, 'alpha', 'ALPHA');
    $this->waitForStatus($one, 'saved');
    expect(editorStored($post))->toBe('<p>ALPHA beta gamma</p><p>one two three</p>');

    // The second editor started from the original and touched the other paragraph.
    editorReplace($two, 'three', 'THREE');
    $this->waitForStatus($two, 'saved');

    expect(editorStored($post))->toBe('<p>ALPHA beta gamma</p><p>one two THREE</p>');
    waitForEditorHtml($two, ['ALPHA', 'THREE']);
    expect(editorHtml($two))->toBe('<p>ALPHA beta gamma</p><p>one two THREE</p>')
        ->and($this->currentStatus($two))->toBe('saved');

    // The merged document is the new base: the next save from this tab
    // changes only what it typed, nothing is reverted.
    editorReplace($two, 'beta', 'BETA');
    $this->waitForStatus($two, 'saved');
    expect(editorStored($post))->toBe('<p>ALPHA BETA gamma</p><p>one two THREE</p>');

    $this->assertNoBrowserErrors($one);
    $this->assertNoBrowserErrors($two);
})->with('rich columns');

test('a poll brings another editor\'s paragraph into a clean editor and merges it into a dirty one', function (string $format) {
    [$post, $url] = editorPost($format, '<p>alpha beta gamma</p><p>one two three</p>');

    $one = visit($url);
    $this->waitUntil($one, editorReady(), 'rich editor');
    config(['filament-autosave.poll_interval' => 500]);
    $two = visit($url);
    $this->waitUntil($two, editorReady(), 'rich editor');

    // Clean: the poll refills the field, inside the editor.
    editorReplace($one, 'alpha', 'ALPHA');
    $this->waitForStatus($one, 'saved');
    waitForEditorHtml($two, ['ALPHA']);
    expect($this->currentStatus($two))->toBe('synced')
        ->and(editorHtml($two))->toBe('<p>ALPHA beta gamma</p><p>one two three</p>');

    // Dirty (the body never validates while it says PENDING): the other
    // editor's paragraph is merged around the unsaved one, which stays.
    editorReplace($two, 'three', 'PENDING');
    $this->waitForStatus($two, 'validation');
    editorReplace($one, 'gamma', 'GAMMA');
    $this->waitForStatus($one, 'saved');
    waitForEditorHtml($two, ['GAMMA', 'PENDING']);
    expect(editorHtml($two))->toBe('<p>ALPHA beta GAMMA</p><p>one two PENDING</p>')
        ->and(editorStored($post))->toBe('<p>ALPHA beta GAMMA</p><p>one two three</p>');

    // Made valid again, the field saves on top of the merged base.
    editorReplace($two, 'PENDING', 'THREE');
    $this->waitForStatus($two, 'saved');
    expect(editorStored($post))->toBe('<p>ALPHA beta GAMMA</p><p>one two THREE</p>');

    $this->assertNoBrowserErrors($one);
    $this->assertNoBrowserErrors($two);
})->with('rich columns');

test('the same words changed by both keep the later save and let the other version be recovered', function (string $format) {
    [$post, $url] = editorPost($format, '<p>alpha beta gamma</p>');

    $one = visit($url);
    $two = visit($url);
    $this->waitUntil($one, editorReady(), 'rich editor');
    $this->waitUntil($two, editorReady(), 'rich editor');

    editorReplace($one, 'beta', 'ONE');
    $this->waitForStatus($one, 'saved');

    editorReplace($two, 'beta', 'TWO');
    $this->waitForStatus($two, 'saved');

    expect(editorStored($post))->toBe('<p>alpha TWO gamma</p>');
    $two->assertPresent('[data-autosave-conflicts]')
        ->assertPresent('[data-autosave-conflict-reason="overlap"]')
        ->assertSee('ONE');

    // Recovering puts the other editor's words back as an ordinary edit.
    $two->click($this->action('recover'));
    waitForEditorHtml($two, ['ONE']);
    expect(editorHtml($two))->toBe('<p>alpha ONE gamma</p>');
    // The earlier "saved" badge is still fresh: wait for the write itself.
    $this->waitForDatabase($two, fn (): bool => editorStored($post) === '<p>alpha ONE gamma</p>', 'the recovered words to be saved');
    expect($two->script('document.querySelector("[data-autosave-conflicts]")'))->toBeNull();

    $this->assertNoBrowserErrors($one);
    $this->assertNoBrowserErrors($two);
})->with('rich columns');

test('a mark on one word and an edit of another word in the same paragraph are both kept', function (string $format) {
    [$post, $url] = editorPost($format, '<p>alpha beta gamma delta</p>');

    $one = visit($url);
    $two = visit($url);
    $this->waitUntil($one, editorReady(), 'rich editor');
    $this->waitUntil($two, editorReady(), 'rich editor');

    $one->script('(() => { const range = '.editorRangeScript('alpha').'; '.RICH_EDITOR.'.chain().focus().setTextSelection(range).setBold().run(); return true })()');
    $this->waitForStatus($one, 'saved');
    expect(editorStored($post))->toBe('<p><strong>alpha</strong> beta gamma delta</p>');

    editorReplace($two, 'delta', 'DELTA');
    $this->waitForStatus($two, 'saved');

    expect(editorStored($post))->toBe('<p><strong>alpha</strong> beta gamma DELTA</p>');
    waitForEditorHtml($two, ['<strong>alpha</strong>', 'DELTA']);
    expect($two->script('document.querySelector("[data-autosave-conflicts]")'))->toBeNull();

    $this->assertNoBrowserErrors($one);
    $this->assertNoBrowserErrors($two);
})->with('rich columns');

test('an image inserted by one editor survives the other editor\'s text edit with its attachment id', function (string $format) {
    Storage::fake('public');
    Storage::disk('public')->put('attachments/pic.gif', base64_decode(substr(BrowserRichMergePostResource::IMAGE_SRC, strlen('data:image/gif;base64,'))));
    [$post, $url] = editorPost($format, '<p>alpha beta gamma</p><p>one two three</p>');

    $one = visit($url);
    $two = visit($url);
    $this->waitUntil($one, editorReady(), 'rich editor');
    $this->waitUntil($two, editorReady(), 'rich editor');

    $one->script('(() => { '.RICH_EDITOR.'.chain().focus("end").insertContent({ type: "image", attrs: { src: '.json_encode(BrowserRichMergePostResource::IMAGE_SRC).', id: "attachments/pic.gif", alt: "pic" } }).run(); return true })()');
    $this->waitForStatus($one, 'saved');
    expect(editorStored($post))->toContain('data-id="attachments/pic.gif"');

    editorReplace($two, 'alpha', 'ALPHA');
    $this->waitForStatus($two, 'saved');

    $stored = editorStored($post);
    expect($stored)->toContain('ALPHA')->toContain('data-id="attachments/pic.gif"')
        ->and(Storage::disk('public')->exists('attachments/pic.gif'))->toBeTrue();
    waitForEditorHtml($two, ['ALPHA']);
    expect($two->script(RICH_EDITOR.'.state.doc.textBetween(0, '.RICH_EDITOR.'.state.doc.content.size)'))->toBe('ALPHA beta gammaone two three')
        ->and($two->script('JSON.stringify('.RICH_EDITOR.'.getJSON()).includes("attachments/pic.gif")'))->toBeTrue();

    $this->assertNoBrowserErrors($one);
    $this->assertNoBrowserErrors($two);
})->with('rich columns');

test('a paragraph with an image removed by one editor stays removed while the other editor types elsewhere', function (string $format) {
    Storage::fake('public');
    Storage::disk('public')->put('attachments/pic.gif', 'gif');
    [$post, $url] = editorPost($format, '<p>alpha beta gamma</p><p><img src="'.BrowserRichMergePostResource::IMAGE_SRC.'" data-id="attachments/pic.gif" alt="pic"></p><p>one two three</p>');

    $one = visit($url);
    $two = visit($url);
    $this->waitUntil($one, editorReady(), 'rich editor');
    $this->waitUntil($two, editorReady(), 'rich editor');

    editorReplace($one, 'alpha', 'ALPHA');
    $this->waitForStatus($one, 'saved');

    // Delete the middle paragraph (the image) in the second tab.
    $two->script('(() => { const e = '.RICH_EDITOR.'; const p = e.state.doc.child(1); const pos = e.state.doc.child(0).nodeSize; e.view.dispatch(e.state.tr.delete(pos, pos + p.nodeSize)); return true })()');
    $this->waitForStatus($two, 'saved');

    expect(editorStored($post))->toBe('<p>ALPHA beta gamma</p><p>one two three</p>');
    waitForEditorHtml($two, ['ALPHA']);
    expect($two->script('JSON.stringify('.RICH_EDITOR.'.getJSON()).includes("attachments/pic.gif")'))->toBeFalse();

    $this->assertNoBrowserErrors($one);
    $this->assertNoBrowserErrors($two);
})->with('rich columns');

test('the caret stays on its word while a poll applies another editor\'s change to a different paragraph', function (string $format) {
    [$post, $url] = editorPost($format, '<p>alpha beta gamma</p><p>one two three</p>');

    $one = visit($url);
    $this->waitUntil($one, editorReady(), 'rich editor');
    config(['filament-autosave.poll_interval' => 500]);
    $two = visit($url);
    $this->waitUntil($two, editorReady(), 'rich editor');

    // Caret after "two" in the second paragraph, nothing typed yet.
    editorCaretAfter($two, 'two');
    $before = editorCaret($two);

    $one->script('(() => { '.RICH_EDITOR.'.chain().focus("start").insertContent("<p>new paragraph</p>").run(); return true })()');
    $this->waitForStatus($one, 'saved');
    waitForEditorHtml($two, ['new paragraph']);

    // The new paragraph sits before the caret: the caret moved with its word.
    $shift = (int) $two->script(RICH_EDITOR.'.state.doc.child(0).nodeSize');
    expect(editorCaret($two))->toBe($before + $shift)
        ->and($two->script(RICH_EDITOR.'.state.doc.textBetween('.RICH_EDITOR.'.state.selection.from - 3, '.RICH_EDITOR.'.state.selection.from)'))->toBe('two');

    // Typing at the caret lands where the user is looking.
    $two->script('(() => { '.RICH_EDITOR.'.chain().focus().insertContent(" more").run(); return true })()');
    $this->waitForStatus($two, 'saved');
    expect(editorStored($post))->toBe('<p>new paragraph</p><p>alpha beta gamma</p><p>one two more three</p>');

    $this->assertNoBrowserErrors($one);
    $this->assertNoBrowserErrors($two);
})->with('rich columns');

test('a custom block comes through a merge whole', function (string $format) {
    [$post, $url] = editorPost($format, '<p>alpha beta gamma</p><div data-type="customBlock" data-id="callout" data-config="'.e(json_encode(['text' => 'Heads up'])).'"></div><p>one two three</p>');

    $one = visit($url);
    $two = visit($url);
    $this->waitUntil($one, editorReady(), 'rich editor');
    $this->waitUntil($two, editorReady(), 'rich editor');

    expect(editorStored($post))->toContain('data-id="callout"');

    editorReplace($one, 'alpha', 'ALPHA');
    $this->waitForStatus($one, 'saved');
    editorReplace($two, 'three', 'THREE');
    $this->waitForStatus($two, 'saved');

    $stored = editorStored($post);
    expect($stored)->toContain('ALPHA')->toContain('THREE')->toContain('data-id="callout"')->toContain('Heads up');
    waitForEditorHtml($two, ['ALPHA', 'THREE']);
    expect($two->script('JSON.stringify('.RICH_EDITOR.'.getJSON())'))->toContain('"customBlock"')->toContain('Heads up');

    $this->assertNoBrowserErrors($one);
    $this->assertNoBrowserErrors($two);
})->with('rich columns');

test('a rich field contended through every retry is adopted from the latest merge and stays dirty', function () {
    $post = Post::create(['title' => 'Original', 'body' => '<p>alpha beta gamma</p>']);
    $page = visit("/admin/rich-contended-posts/{$post->getKey()}/edit");
    $this->waitUntil($page, editorReady(), 'rich editor');

    // Another editor wrote after this tab loaded; every write attempt here loses.
    $post->update(['body' => '<p>alpha beta GAMMA</p>']);
    editorReplace($page, 'alpha', 'ALPHA');
    $this->waitForStatus($page, 'validation');

    // Nothing written, nothing lost: the editor adopts the merge against the
    // latest value, reports why, and the field stays dirty for the next cycle.
    expect(editorStored($post))->toBe('<p>alpha beta GAMMA</p>');
    waitForEditorHtml($page, ['ALPHA', 'GAMMA']);
    expect(editorHtml($page))->toBe('<p>ALPHA beta GAMMA</p>');
    $page->assertPresent('[data-autosave-conflict-reason="contended"]');

    $controller = 'Alpine.$data(document.querySelector("[data-autosave-status]"))';
    expect($page->script($controller.'.mergeSync.base("body")'))->toBe($page->script('FilamentAutosaveRichMerge.docs.normalize('.RICH_EDITOR.', "<p>alpha beta GAMMA</p>")'))
        ->and($page->script('JSON.stringify('.$controller.'.mergePatches().body.base)'))->toContain('alpha beta GAMMA')
        ->and($page->script('JSON.stringify('.$controller.'.baselineJson) !== JSON.stringify(JSON.stringify('.$controller.'.stateValue()))'))->toBeTrue();

    $this->assertNoBrowserErrors($page);
});

test('text typed while the save is in flight is kept when the merged document lands', function (string $format) {
    [$post, $url] = editorPost($format, '<p>alpha beta gamma</p><p>one two three</p>');

    $one = visit($url);
    $two = visit($url);
    $this->waitUntil($one, editorReady(), 'rich editor');
    $this->waitUntil($two, editorReady(), 'rich editor');

    editorReplace($one, 'alpha', 'ALPHA');
    $this->waitForStatus($one, 'saved');

    // As soon as the save is on the wire, keep typing at the end of the
    // second paragraph; the reply merges the first one around it.
    $two->script('(() => { const c = Alpine.$data(document.querySelector("[data-autosave-status]")); const tick = setInterval(() => { if (c.status === "saving") { clearInterval(tick); '.RICH_EDITOR.'.chain().focus("end").insertContent(" typed").run() } }, 5); return true })()');
    editorReplace($two, 'three', 'THREE');
    $this->waitForStatus($two, 'saved');

    waitForEditorHtml($two, ['ALPHA', 'THREE typed']);
    $this->waitForDatabase($two, fn (): bool => editorStored($post) === '<p>ALPHA beta gamma</p><p>one two THREE typed</p>', 'the in-flight text to be saved');
    expect(editorHtml($two))->toBe('<p>ALPHA beta gamma</p><p>one two THREE typed</p>')
        ->and($two->script('document.querySelector("[data-autosave-conflicts]")'))->toBeNull();

    $this->assertNoBrowserErrors($one);
    $this->assertNoBrowserErrors($two);
})->with('rich columns');
