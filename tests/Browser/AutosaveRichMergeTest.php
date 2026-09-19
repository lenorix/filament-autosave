<?php

use Illuminate\Support\Facades\Storage;
use Lenorix\FilamentAutosave\Tests\BrowserTestCase;
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
function richPost(string $format, string $html): array
{
    if ($format === 'json') {
        $post = RichJsonPost::create(['title' => 'Original', 'body' => richDoc($html)]);

        return [$post, "/admin/rich-json-merge-posts/{$post->getKey()}/edit"];
    }

    $post = Post::create(['title' => 'Original', 'body' => $html]);

    return [$post, "/admin/rich-merge-posts/{$post->getKey()}/edit"];
}

/** Tiptap JSON for HTML, through the same editor the resource uses. */
function richDoc(string $html): array
{
    return RichMergeEditor::make()->setContent($html)->getDocument();
}

/** The stored body as HTML, whichever column format. */
function richStored(object $post): string
{
    $body = $post->fresh()->body;

    return is_array($body)
        ? RichMergeEditor::make()->setContent($body)->getHTML()
        : (string) $body;
}

function richHtml(object $page): string
{
    return (string) $page->script(RICH_EDITOR.'.getHTML()');
}

function richText(object $page): string
{
    return (string) $page->script(RICH_EDITOR.'.getText()');
}

/** [from, to] of a text within the document, as ProseMirror positions. */
function richRangeScript(string $search): string
{
    return '(() => { const editor = '.RICH_EDITOR.'; let range = null; editor.state.doc.descendants((node, pos) => {'
        .' if (range || !node.isText) return; const i = node.text.indexOf('.json_encode($search).'); if (i >= 0) range = { from: pos + i, to: pos + i + '.mb_strlen($search).' } });'
        .' return range })()';
}

/** Replace a text in the editor as the user would: focus, select, type. */
function richReplace(object $page, string $search, string $replacement): void
{
    $found = $page->script('(() => { const range = '.richRangeScript($search).'; if (!range) return false; '
        .RICH_EDITOR.'.chain().focus().insertContentAt(range, '.json_encode($replacement).').run(); return true })()');

    if (! $found) {
        throw new RuntimeException("Text [{$search}] not found in the editor.");
    }
}

/** Put the caret right after a text. */
function richCaretAfter(object $page, string $search): void
{
    $page->script('(() => { const range = '.richRangeScript($search).'; '.RICH_EDITOR.'.chain().focus().setTextSelection(range.to).run(); return true })()');
}

function richCaret(object $page): int
{
    return (int) $page->script(RICH_EDITOR.'.state.selection.from');
}

/** JavaScript expression: the rich editor is on the page and ready. */
function richReady(): string
{
    return '(() => { try { return typeof FilamentAutosaveRichMerge === "object" && '.RICH_EDITOR.' != null } catch (e) { return false } })()';
}

/** Wait until the editor's HTML contains every fragment, yielding to the server meanwhile. */
function waitForRichHtml(object $page, array $fragments, int $timeoutMs = 10_000): void
{
    $deadline = hrtime(true) + $timeoutMs * 1_000_000;

    do {
        $html = richHtml($page);
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
    [, $url] = richPost('html', '<p>alpha beta gamma</p>');
    $page = visit($url);

    $this->waitUntil($page, richReady(), 'rich editor');

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
    [$post, $url] = richPost($format, '<p>alpha beta gamma</p><p>one two three</p>');

    $one = visit($url);
    $two = visit($url);
    $this->waitUntil($one, richReady(), 'rich editor');
    $this->waitUntil($two, richReady(), 'rich editor');

    richReplace($one, 'alpha', 'ALPHA');
    $this->waitForStatus($one, 'saved');
    expect(richStored($post))->toBe('<p>ALPHA beta gamma</p><p>one two three</p>');

    // The second editor started from the original and touched the other paragraph.
    richReplace($two, 'three', 'THREE');
    $this->waitForStatus($two, 'saved');

    expect(richStored($post))->toBe('<p>ALPHA beta gamma</p><p>one two THREE</p>');
    waitForRichHtml($two, ['ALPHA', 'THREE']);
    expect(richHtml($two))->toBe('<p>ALPHA beta gamma</p><p>one two THREE</p>')
        ->and($this->currentStatus($two))->toBe('saved');

    // The first editor is idle: the poll brings the merged document in.
    config(['filament-autosave.poll_interval' => 500]);
    $one->reload(); $this->waitUntil($one, richReady(), 'rich editor');
    richReplace($one, 'gamma', 'GAMMA');
    $this->waitForStatus($one, 'saved');
    expect(richStored($post))->toBe('<p>ALPHA beta GAMMA</p><p>one two THREE</p>');
    waitForRichHtml($two, ['GAMMA']);
    expect($this->currentStatus($two))->toBe('synced');

    $this->assertNoBrowserErrors($one);
    $this->assertNoBrowserErrors($two);
})->with('rich columns');
