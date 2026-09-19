<?php

namespace Lenorix\FilamentAutosave\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\BrowserUser;
use RuntimeException;

/**
 * Drives the real admin panel in a real browser through Pest's browser
 * plugin (Playwright underneath).
 *
 * The plugin serves the application in-process (an amphp socket server that
 * hands each request to the same Laravel kernel this test booted), so the
 * `:memory:` database, package providers, model events and schema from
 * {@see IntegrationTestCase} carry over unchanged. Only Filament's compiled
 * assets need to exist on disk: the page loads Alpine, the autosave
 * controller and the package stylesheet through them.
 *
 * Tests select the indicator through the `data-autosave-status` and
 * `data-autosave-action` attributes the view exposes, never through
 * translated text, so a copy change cannot break them.
 *
 * Every test runs as an authenticated editor, as a real panel does. Drafts
 * and Undo snapshots are scoped by owner; a guest's scope falls back to the
 * session id, and the in-process server does not carry a session cookie
 * between requests, so a guest would get a fresh scope on every request.
 */
abstract class BrowserTestCase extends IntegrationTestCase
{
    /** Debounce used in the browser; short for speed, still a real debounce. */
    public const int DEBOUNCE_MS = 300;

    protected function setUp(): void
    {
        parent::setUp();

        View::addNamespace('autosave-fixtures', __DIR__.'/Fixtures/views');

        // Drafts and Undo snapshots live in the cache; every test starts clean.
        Cache::flush();

        if (! Schema::hasTable('users')) {
            Schema::create('users', function ($table): void {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->string('password');
                $table->rememberToken();
            });
        }

        $this->actingAs(BrowserUser::create([
            'name' => 'Editor',
            'email' => 'editor@example.test',
            'password' => bcrypt('secret'),
        ]));

        if (! is_file(public_path('js/filament/filament/app.js'))
            || ! is_file(public_path('css/lenorix/filament-autosave/filament-autosave.css'))) {
            $this->artisan('filament:assets');
        }
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('filament-autosave.debounce', self::DEBOUNCE_MS);
    }

    /** Selector for the indicator root while it reports the given status. */
    public static function indicator(string $status): string
    {
        return sprintf('[data-autosave-status="%s"]', $status);
    }

    /** Selector for one of the indicator's action links. */
    public static function action(string $action): string
    {
        return sprintf('[data-autosave-action="%s"]', $action);
    }

    /** Selector for a Filament form input by its full state path id. */
    public static function field(string $id): string
    {
        return sprintf('[id="%s"]', $id);
    }

    /**
     * Wait until the indicator reports the given status.
     *
     * Element assertions on the awaitable page retry until the plugin timeout,
     * so this is a bounded poll, never a sleep sized to the debounce.
     */
    protected function waitForStatus(object $page, string $status): object
    {
        return $this->waitUntil(
            $page,
            sprintf(
                'document.querySelector("[data-autosave-status]")?.dataset.autosaveStatus === %s',
                json_encode($status),
            ),
            "indicator status [{$status}]",
        );
    }

    /** Wait until an input holds exactly the given value. */
    protected function waitForInputValue(object $page, string $selector, string $value): object
    {
        return $this->waitUntil(
            $page,
            sprintf('document.querySelector(%s)?.value === %s', json_encode($selector), json_encode($value)),
            "input {$selector} to equal ".json_encode($value),
        );
    }

    /**
     * Type into a field and wait for the debounce to flush into a settled
     * status (saved, validation, error...), so the caller can assert the
     * outcome rather than the transient states.
     */
    protected function typeAndSettle(object $page, string $selector, string $value): object
    {
        $page->fill($selector, $value);

        return $this->waitUntil(
            $page,
            'document.querySelector("[data-autosave-status]")?.dataset.autosaveStatus !== "unsaved"'
                .' && document.querySelector("[data-autosave-status]")?.dataset.autosaveStatus !== "saving"',
            'autosave to settle after typing',
            timeoutMs: 15_000,
        );
    }

    /** Current status reported by the indicator, `idle` when hidden. */
    protected function currentStatus(object $page): string
    {
        return (string) ($page->script('document.querySelector("[data-autosave-status]")?.dataset.autosaveStatus') ?? 'idle');
    }

    /** Assert the page has not logged any JavaScript error. */
    protected function assertNoBrowserErrors(object $page): void
    {
        $page->assertNoJavaScriptErrors();
    }

    /**
     * Poll a JavaScript expression until truthy; on timeout, fail with the
     * page text and console so the cause is visible without a screenshot.
     */
    protected function waitUntil(object $page, string $expression, string $description, int $timeoutMs = 10_000): object
    {
        $deadline = hrtime(true) + $timeoutMs * 1_000_000;

        do {
            if ($page->script($expression)) {
                return $page;
            }

            usleep(100_000);
        } while (hrtime(true) < $deadline);

        $status = $this->currentStatus($page);
        $text = mb_substr((string) $page->script('document.body.innerText'), 0, 2000);
        $screenshot = $page->page()->screenshot();

        throw new RuntimeException(sprintf(
            "Timed out after %dms waiting for %s.\nIndicator status: %s\nScreenshot: %s\nPage text:\n%s",
            $timeoutMs,
            $description,
            $status,
            $screenshot ?? 'unavailable',
            $text,
        ));
    }
}
