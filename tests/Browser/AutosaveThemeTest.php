<?php

use Lenorix\FilamentAutosave\Tests\BrowserTestCase;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;

/**
 * Every indicator state, in light and dark mode, must be legible.
 *
 * The indicator is built only from Filament components, so this is really a
 * check that we use them correctly (a colour on a badge, a heading in a
 * callout) rather than a check of our own CSS: there is none.
 *
 * States are forced through the Alpine component's data so each one can be
 * measured without staging the server round-trip that would produce it.
 */
const THEME_ROOT = '.fi-autosave-indicator';

/** Statuses and the extra data each needs; `text` is where the primary label lives. */
function themeStates(): array
{
    return [
        'draftAvailable' => ['text' => '.fi-badge', 'actions' => ['restore', 'discard']],
        'unsaved' => ['text' => '.fi-badge'],
        'saving' => ['text' => '.fi-badge'],
        'saved' => ['text' => '.fi-badge', 'actions' => ['undo']],
        'saved+pending' => ['status' => 'saved', 'pendingFields' => ['slug'], 'text' => '.fi-callout-heading', 'badges' => 1],
        'undone' => ['text' => '.fi-badge'],
        'restored' => ['text' => '.fi-badge'],
        'error' => ['text' => '.fi-badge'],
        'conflict' => ['text' => '.fi-badge'],
        'validation' => [
            'validationErrors' => ['title' => ['The title field is required.']],
            'pendingFields' => ['title', 'slug'],
            'text' => '.fi-callout-heading',
            'description' => 'The title field is required.',
            'badges' => 3,
        ],
        'synced' => ['text' => '.fi-badge'],
        'synced+stale' => ['status' => 'synced', 'staleFields' => ['title'], 'text' => '.fi-callout-heading', 'badges' => 1],
    ];
}

function themeForceState(object $page, string $status, array $state): void
{
    $data = json_encode([
        'validationErrors' => $state['validationErrors'] ?? [],
        'pendingFields' => $state['pendingFields'] ?? [],
        'staleFields' => $state['staleFields'] ?? [],
        'timestamp' => '12:00',
    ]);

    $page->script(sprintf(<<<'JS'
        (() => {
            const data = Alpine.$data(document.querySelector(%s));
            clearTimeout(data.fadeTimer);
            Object.assign(data, %s);
            // Status values come from the server-side enum; resolve the key through the map.
            data.status = data.statuses[%s] ?? %s;
        })()
        JS, json_encode(THEME_ROOT), $data, json_encode($status), json_encode($status)));
}

/**
 * WCAG contrast between an element's text and its composited background.
 *
 * Filament paints badges and callouts with translucent colour-mix()
 * backgrounds, so the background is blended down through the ancestors
 * until an opaque one is reached. Colours are normalised through a canvas so
 * oklch()/color-mix() values resolve to plain RGBA.
 */
function themeContrast(object $page, string $selector): float
{
    return (float) $page->script(sprintf(<<<'JS'
        (() => {
            const el = document.querySelector(%s);
            if (! el) return 0;
            const ctx = document.createElement('canvas').getContext('2d');
            const rgba = (css) => {
                ctx.clearRect(0, 0, 1, 1);
                ctx.fillStyle = '#000'; ctx.fillStyle = css;
                ctx.fillRect(0, 0, 1, 1);
                const [r, g, b, a] = ctx.getImageData(0, 0, 1, 1).data;
                return [r, g, b, a / 255];
            };
            const blend = (top, bottom) => top.map((c, i) => i === 3 ? 1 : Math.round(c * top[3] + bottom[i] * (1 - top[3])));
            let bg = [255, 255, 255, 0];
            const layers = [];
            for (let node = el; node; node = node.parentElement) {
                const c = rgba(getComputedStyle(node).backgroundColor);
                if (c[3] > 0) layers.unshift(c);
                if (c[3] >= 1) break;
            }
            bg = [255, 255, 255, 1];
            for (const layer of layers) bg = blend(layer, bg);
            const lum = ([r, g, b]) => {
                const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
                return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
            };
            const text = rgba(getComputedStyle(el).color);
            const l1 = lum(text), l2 = lum(bg);
            return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
        })()
        JS, json_encode($selector)));
}

function themeTextColor(object $page, string $selector): string
{
    return (string) $page->script(sprintf(
        '(() => { const el = document.querySelector(%s); return el ? getComputedStyle(el).color : ""; })()',
        json_encode($selector),
    ));
}

test('every indicator state is legible in light and in dark mode', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);
    $page = visit("/admin/posts/{$post->getKey()}/edit");
    $this->waitUntil($page, 'document.querySelector('.json_encode(THEME_ROOT).') !== null', 'indicator rendered');
    $this->waitUntil($page, 'typeof window.Alpine === "object" && document.querySelector('.json_encode(THEME_ROOT).')._x_dataStack !== undefined', 'indicator booted');

    $report = [];

    foreach (['light', 'dark'] as $theme) {
        $page->script(sprintf("document.documentElement.classList.%s('dark')", $theme === 'dark' ? 'add' : 'remove'));

        foreach (themeStates() as $name => $state) {
            $status = $state['status'] ?? $name;
            themeForceState($page, $status, $state);

            $selector = THEME_ROOT.' '.$state['text'];
            $this->waitUntil($page, 'document.querySelector('.json_encode($selector).') !== null', "{$name} rendered in {$theme}");

            $ratio = themeContrast($page, $selector);
            $report[$name][$theme] = ['ratio' => round($ratio, 2), 'color' => themeTextColor($page, $selector)];

            expect($ratio)->toBeGreaterThanOrEqual(3.0, "{$name} in {$theme}: contrast {$ratio}");

            foreach ($state['actions'] ?? [] as $action) {
                expect($page->script('document.querySelector('.json_encode(THEME_ROOT.' '.BrowserTestCase::action($action)).') !== null'))
                    ->toBeTrue("{$name}: {$action} control present");
            }

            if (isset($state['badges'])) {
                expect((int) $page->script('document.querySelectorAll('.json_encode(THEME_ROOT.' .fi-callout-footer .fi-badge').').length'))
                    ->toBe($state['badges'], "{$name}: field badges");
            }

            if (isset($state['description'])) {
                expect((string) $page->script('document.querySelector('.json_encode(THEME_ROOT.' .fi-callout-description').')?.innerText ?? ""'))
                    ->toContain($state['description']);
            }
        }
    }

    // The theme actually changes the rendering, it is not one palette for both.
    foreach ($report as $name => $themes) {
        expect($themes['dark']['color'])->not->toBe($themes['light']['color'], "{$name} renders the same colour in both themes");
    }

    fwrite(STDERR, "\nCONTRAST ".json_encode($report)."\n");
    $this->assertNoBrowserErrors($page);
});
