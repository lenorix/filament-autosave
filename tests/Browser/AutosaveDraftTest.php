<?php

use Lenorix\FilamentAutosave\AutosaveManager;
use Lenorix\FilamentAutosave\Tests\BrowserTestCase;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\CreatePost;

function draftKey(): string
{
    return AutosaveManager::cacheKey(CreatePost::class);
}

test('a create page keeps a draft across reloads, restores it on request, and discards it on request', function () {
    $page = visit('/admin/posts/create');
    expect($this->currentStatus($page))->toBe('idle');

    $page->fill(BrowserTestCase::field('form.title'), 'Drafted title');
    $page->fill(BrowserTestCase::field('form.slug'), 'drafted-slug');
    $this->waitForStatus($page, 'saved');

    expect(Post::count())->toBe(0)
        ->and(AutosaveManager::restoreDraft(draftKey()))->toMatchArray(['title' => 'Drafted title', 'slug' => 'drafted-slug']);

    // Coming back later: the inputs are empty but the draft is offered.
    $returned = visit('/admin/posts/create')
        ->assertValue(BrowserTestCase::field('form.title'), '')
        ->assertPresent(BrowserTestCase::indicator('draft_available'))
        ->assertPresent(BrowserTestCase::action('restore'))
        ->assertPresent(BrowserTestCase::action('discard'));

    $returned->click(BrowserTestCase::action('restore'));
    $this->waitForStatus($returned, 'restored');
    $this->waitForInputValue($returned, BrowserTestCase::field('form.title'), 'Drafted title');
    $returned->assertValue(BrowserTestCase::field('form.slug'), 'drafted-slug');

    // Restoring does not itself re-save; the draft is simply consumed.
    $this->waitForStatus($returned, 'idle');

    // Discarding from a fresh visit removes it for good.
    $again = visit('/admin/posts/create')->assertPresent(BrowserTestCase::action('discard'));
    $again->click(BrowserTestCase::action('discard'));
    $this->waitForStatus($again, 'idle');

    expect(AutosaveManager::restoreDraft(draftKey()))->toBeNull();

    visit('/admin/posts/create')->assertMissing(BrowserTestCase::indicator('draft_available'));

    $this->assertNoBrowserErrors($again);
});

test('creating the record clears its draft', function () {
    $page = visit('/admin/posts/create');
    $page->fill(BrowserTestCase::field('form.title'), 'Will be created');
    $this->waitForStatus($page, 'saved');

    expect(AutosaveManager::restoreDraft(draftKey()))->not->toBeNull();

    // "Create" is also a breadcrumb, and the user menu holds a hidden
    // "Sign out" submit button, so target the create form's own submit.
    $page->click('form[wire\\:submit="create"] button[type="submit"]');
    $this->waitUntil($page, 'location.pathname.endsWith("/edit")', 'redirect to the edit page after create');

    expect(Post::where('title', 'Will be created')->exists())->toBeTrue()
        ->and(AutosaveManager::restoreDraft(draftKey()))->toBeNull();

    visit('/admin/posts/create')->assertMissing(BrowserTestCase::indicator('draft_available'));
});
