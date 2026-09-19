<?php

use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Lenorix\FilamentAutosave\AutosavePlugin;
use Lenorix\FilamentAutosave\Tests\Fixtures\AutosaveCreateFormComponent;
use Lenorix\FilamentAutosave\Tests\Fixtures\AutosaveEditFormComponent;
use Livewire\Features\SupportScriptsAndAssets\SupportScriptsAndAssets;

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

    // Undo is rendered in every mode and hidden client-side while
    // $wire.autosaveCanUndo is false, so record-backed generic forms get it too.
    // It appears twice: in the plain "saved" badge and in the callout shown
    // when some fields were skipped. The merge conflict callout adds its
    // recover and dismiss links.
    expect($xpath->query('//button[@type="button"]')->length)->toBe(6)
        ->and($xpath->query('//button[@data-autosave-action="undo"]')->length)->toBe(2)
        ->and($xpath->query('//button[@data-autosave-action="recover"]')->length)->toBe(1)
        ->and($xpath->query('//button[@data-autosave-action="dismiss-conflicts"]')->length)->toBe(1);

    // Everything visual is a Filament component; none of our former helper
    // classes or raw Tailwind utilities remain (Filament's own markup may
    // carry whatever classes its theme compiles, so only ours are asserted).
    expect($html)->toContain('fi-callout')
        ->not->toContain('fi-autosave-stack')->not->toContain('fi-autosave-note')->not->toContain('fi-autosave-list')
        ->not->toContain('text-gray-')->not->toContain('flex-col')->not->toContain('text-xs');
})->with(['edit', 'create', 'form']);

test('the indicator ships the merge runtime only for a component that lists merge fields', function () {
    $component = new class
    {
        /** @return list<string> */
        public function getAutosaveMergeFields(): array
        {
            return ['body', 'summary'];
        }
    };

    $html = view('filament-autosave::autosave-indicator', ['mode' => 'edit', '__livewire' => $component])->render();

    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $controller = $xpath->query('//*[@class="fi-autosave-indicator"]')->item(0)->getAttribute('x-data');

    // One inline, dependency-free script (no asset publishing, no build
    // step) exposing the engine, the sync state and the input applier. It
    // goes through Livewire's @assets, so it lands in the page head once
    // and never travels in the component's own HTML (nor its re-renders).
    $assets = implode('', SupportScriptsAndAssets::$nonLivewireAssets);

    expect($html)->not->toContain('<script')
        ->and(substr_count($assets, '<script data-autosave-merge>'))->toBe(1)
        ->and($assets)->toContain('window.FilamentAutosaveMerge = window.FilamentAutosaveMerge ||')
        ->and($assets)->toContain('createSync', 'makePatch', 'mapOffset', 'toInput')
        ->and($controller)->toContain('mergeFields: JSON.parse(', 'body', 'summary');

    $plain = view('filament-autosave::autosave-indicator', ['mode' => 'edit', '__livewire' => new class
    {
        /** @return list<string> */
        public function getAutosaveMergeFields(): array
        {
            return [];
        }
    }])->render();

    expect($plain)->not->toContain('<script')
        ->and($plain)->toContain('mergeFields: []');
});

test('plugin fallbacks match the shipped config file when a key is missing', function () {
    $shipped = require dirname(__DIR__, 2).'/config/filament-autosave.php';

    foreach (['debounce', 'draft_ttl', 'undo_ttl', 'poll_interval', 'show_saved_at', 'position', 'merge_fields'] as $key) {
        config(["filament-autosave.{$key}" => null]);
    }

    $plugin = AutosavePlugin::make();

    expect($plugin->getDebounce())->toBe($shipped['debounce'])
        ->and($plugin->getCacheTtl())->toBe($shipped['draft_ttl'])
        ->and($plugin->getUndoCacheTtl())->toBe($shipped['undo_ttl'])
        ->and($plugin->getPollInterval())->toBe($shipped['poll_interval'])
        ->and($plugin->shouldShowTimestamp())->toBe($shipped['show_saved_at'])
        ->and($plugin->getIndicatorPosition())->toBe($shipped['position'])
        ->and($plugin->getMergeFields())->toBe($shipped['merge_fields']);
});
