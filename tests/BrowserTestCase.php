<?php

namespace Lenorix\FilamentAutosave\Tests;

/**
 * Drives the real admin panel in a real browser through Pest's browser
 * plugin (Playwright underneath).
 *
 * The plugin serves the application in-process, so the `:memory:` database,
 * package providers and schema from {@see IntegrationTestCase} carry over
 * unchanged. Only the compiled assets need to exist on disk: the page loads
 * Alpine, the autosave controller and the package stylesheet through them.
 */
abstract class BrowserTestCase extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! is_file(public_path('js/filament/filament/app.js'))
            || ! is_file(public_path('css/lenorix/filament-autosave/filament-autosave.css'))) {
            $this->artisan('filament:assets');
        }
    }
}
