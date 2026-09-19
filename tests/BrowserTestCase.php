<?php

namespace Lenorix\FilamentAutosave\Tests;

/**
 * Drives the real admin panel in a real browser through Pest's browser
 * plugin (Playwright underneath).
 *
 * The plugin serves the application in-process, so the `:memory:` database,
 * package providers and schema from {@see IntegrationTestCase} carry over
 * unchanged. Only Filament's compiled assets need to exist on disk: the
 * page loads Alpine and the autosave controller through them.
 */
abstract class BrowserTestCase extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! is_file(public_path('js/filament/filament/app.js'))) {
            $this->artisan('filament:assets');
        }
    }
}
