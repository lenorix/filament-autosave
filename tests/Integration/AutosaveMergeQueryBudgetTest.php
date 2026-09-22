<?php

use Illuminate\Support\Facades\DB;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages\MergeEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\PlainRichPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\RichMerge\RichMergeHtmlEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\RichUpload\PlainRichEditorEditPost;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/** @return array<int, string> SQL of every query one Livewire call issued. */
function mergeQueriesDuring(Testable $page, string $method, array $arguments = []): array
{
    DB::enableQueryLog();
    DB::flushQueryLog();

    $page->call($method, ...$arguments);

    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    return $queries;
}

/*
 * Ceilings. Merging a plain-text field costs one conditional UPDATE for the
 * column instead of the plain UPDATE. Merging a RichEditor costs that plus
 * exactly one SELECT of the rich columns before the form dehydrates, so the
 * editor's attachment cleanup runs on the merged document; nothing else,
 * however large the document.
 */

test('a rich merge adds exactly one query over a plain-text merge', function () {
    $post = Post::create(['title' => 'alpha beta', 'slug' => 'greek']);
    $text = Livewire::test(MergeEditPost::class, ['record' => $post->getKey()]);
    Post::query()->whereKey($post->getKey())->update(['title' => 'ALPHA beta']);
    $text->set('data.title', 'alpha BETA');
    $textQueries = mergeQueriesDuring($text, 'autosave', [['title' => ['base' => 'alpha beta', 'ours' => 'alpha BETA']]]);

    $richPost = PlainRichPost::create(['title' => 'Post', 'body' => '<p>alpha</p><p>beta</p>']);
    $rich = Livewire::test(RichMergeHtmlEditPost::class, ['record' => $richPost->getKey()]);
    PlainRichPost::query()->whereKey($richPost->getKey())->update(['body' => '<p>ALPHA</p><p>beta</p>']);
    $rich->set('data.body', richDoc('alpha', 'BETA'));
    $richQueries = mergeQueriesDuring($rich, 'autosave', [['body' => ['base' => richDoc('alpha', 'beta')]]]);

    expect($richPost->fresh()->body)->toBe('<p>ALPHA</p><p>BETA</p>')
        ->and(count($richQueries))->toBe(count($textQueries) + 1)
        ->and(array_filter($richQueries, fn (string $sql): bool => str_starts_with($sql, 'select "body"') || str_starts_with($sql, 'select `body`')))->toHaveCount(1);
});

test('a rich save without a base costs the same as an unlisted rich editor', function () {
    $listed = PlainRichPost::create(['title' => 'Post', 'body' => '<p>alpha</p>']);
    $unlisted = PlainRichPost::create(['title' => 'Post', 'body' => '<p>alpha</p>']);

    $a = Livewire::test(RichMergeHtmlEditPost::class, ['record' => $listed->getKey()])->set('data.body', richDoc('beta'));
    $b = Livewire::test(PlainRichEditorEditPost::class, ['record' => $unlisted->getKey()])->set('data.body', richDoc('beta'));

    expect(count(mergeQueriesDuring($a, 'autosave')))->toBe(count(mergeQueriesDuring($b, 'autosave')));
});

test('a poll on a rich mergeable field adds no query over a plain field', function () {
    $post = PlainRichPost::create(['title' => 'Post', 'body' => '<p>alpha</p>']);
    $listed = Livewire::test(RichMergeHtmlEditPost::class, ['record' => $post->getKey()]);
    $unlisted = Livewire::test(PlainRichEditorEditPost::class, ['record' => $post->getKey()]);

    PlainRichPost::query()->whereKey($post->getKey())->update(['body' => '<p>ALPHA</p>']);

    expect(count(mergeQueriesDuring($listed, 'syncAutosave')))->toBeLessThanOrEqual(count(mergeQueriesDuring($unlisted, 'syncAutosave')))
        ->and(richTexts($listed->get('data.body')))->toBe(['ALPHA']);
});
