<?php

use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Lenorix\FilamentAutosave\AutosavePlugin;
use Lenorix\FilamentAutosave\Tests\Fixtures\AutosaveCreateFormComponent;
use Lenorix\FilamentAutosave\Tests\Fixtures\AutosaveEditFormComponent;

test('mode detection identifies edit pages', function () {
    $mode = (fn ($c) => $this->detectMode($c))->call(autosavePlugin(), AutosaveEditFormComponent::class);

    expect($mode)->toBe('edit');
});

test('mode detection identifies create pages', function () {
    $mode = (fn ($c) => $this->detectMode($c))->call(autosavePlugin(), AutosaveCreateFormComponent::class);

    expect($mode)->toBe('create');
});

test('mode detection yields null for unsupported classes', function () {
    $mode = (fn ($c) => $this->detectMode($c))->call(autosavePlugin(), stdClass::class);

    expect($mode)->toBeNull();
});

test('mode resolution bypasses excluded pages', function () {
    $plugin = autosavePlugin()->exceptPages([AutosaveEditFormComponent::class]);

    $mode = (fn ($s) => $this->resolveAutosaveMode($s))->call($plugin, [AutosaveEditFormComponent::class]);

    expect($mode)->toBeNull();
});

test('mode resolution picks the first page that qualifies', function () {
    $mode = (fn ($s) => $this->resolveAutosaveMode($s))
        ->call(autosavePlugin(), [stdClass::class, AutosaveCreateFormComponent::class]);

    expect($mode)->toBe('create');
});

test('mode cache stays separate per plugin instance', function () {
    // Simulate stale data from another panel.
    (fn () => $this->modeCache[AutosaveEditFormComponent::class] = 'create')->call(new AutosavePlugin);

    $fresh = new AutosavePlugin;
    $mode = (fn ($s) => $this->resolveAutosaveMode($s))->call($fresh, [AutosaveEditFormComponent::class]);

    expect($mode)->toBe('edit');
});

test('the header renders the indicator without a second copy at the page end', function () {
    $plugin = autosavePlugin();
    $plugin->boot(Panel::make());

    $scopes = [AutosaveEditFormComponent::class];

    FilamentView::renderHook(PanelsRenderHook::PAGE_START, scopes: $scopes);
    $header = (string) FilamentView::renderHook(PanelsRenderHook::PAGE_HEADER_ACTIONS_BEFORE, scopes: $scopes);
    $end = (string) FilamentView::renderHook(PanelsRenderHook::PAGE_END, scopes: $scopes);

    expect($header)->toContain('fi-autosave-indicator');
    expect($end)->toBe('');
});

test('the indicator lands at the page end when the header hook is missing', function () {
    $plugin = autosavePlugin();
    $plugin->boot(Panel::make());

    $scopes = [AutosaveEditFormComponent::class];

    // Simulate a custom header without the actions hook.
    FilamentView::renderHook(PanelsRenderHook::PAGE_START, scopes: $scopes);
    $end = (string) FilamentView::renderHook(PanelsRenderHook::PAGE_END, scopes: $scopes);

    expect($end)->toContain('fi-autosave-indicator');
});

test('the page-end hook renders nothing where autosave is not in play', function () {
    $plugin = autosavePlugin();
    $plugin->boot(Panel::make());

    FilamentView::renderHook(PanelsRenderHook::PAGE_START, scopes: [stdClass::class]);
    $end = (string) FilamentView::renderHook(PanelsRenderHook::PAGE_END, scopes: [stdClass::class]);

    expect($end)->toBe('');
});

test('plugin debounce and draft lifetime fall back to the configuration', function () {
    config(['filament-autosave.debounce' => 2500, 'filament-autosave.draft_ttl' => 48]);

    expect(autosavePlugin()->getDebounce())->toBe(2500)
        ->and(autosavePlugin()->getCacheTtl())->toBe(48);
});

test('resolve() serves configuration outside any mounted panel', function () {
    config(['filament-autosave.debounce' => 2500]);

    expect(AutosavePlugin::resolve()->getDebounce())->toBe(2500);
});

test('plugin undo lifetime falls back to the configuration', function () {
    config(['filament-autosave.undo_ttl' => 45]);

    expect(autosavePlugin()->getUndoCacheTtl())->toBe(45);
});

test('plugin undo lifetime defaults to ninety minutes', function () {
    expect(autosavePlugin()->getUndoCacheTtl())->toBe(90);
});

test('plugin settings outweigh the configured undo lifetime', function () {
    config(['filament-autosave.undo_ttl' => 45]);

    expect(autosavePlugin()->undoCacheTtl(60)->getUndoCacheTtl())->toBe(60);
});

test('plugin settings outweigh the configured timestamp visibility', function () {
    config(['filament-autosave.show_saved_at' => false]);

    expect(autosavePlugin()->shouldShowTimestamp())->toBeFalse()
        ->and(autosavePlugin()->showTimestamp(true)->shouldShowTimestamp())->toBeTrue();
});

test('indicator position falls back to the configuration', function () {
    config(['filament-autosave.position' => 'after']);

    expect(autosavePlugin()->getIndicatorPosition())->toBe('after');
});

test('plugin indicator position outweighs the configuration', function () {
    config(['filament-autosave.position' => 'before']);

    expect(autosavePlugin()->indicatorPosition('after')->getIndicatorPosition())->toBe('after');
});

test('the indicator ships its controller inline', function (string $mode) {
    $html = view('filament-autosave::autosave-indicator', [
        'mode' => $mode,
        'debounce' => 2300,
    ])->render();

    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $indicator = $xpath->query('//*[@class="fi-autosave-indicator"]')->item(0);
    $controller = $indicator->getAttribute('x-data');

    expect($controller)->toContain('(function (', 'debounce: 2300', "mode: '$mode'", 'destroy()')
        ->and($html)->toContain('fi-badge', 'fi-link')
        ->not->toContain('x-load', '<script', '<link');

    expect($xpath->query('//button[@type="button"]')->length)->toBe($mode === 'edit' ? 3 : 2);
})->with(['edit', 'create', 'form']);
