<?php

require __DIR__.'/Support/helpers.php';

use Lenorix\FilamentAutosave\Tests\BrowserTestCase;
use Lenorix\FilamentAutosave\Tests\IntegrationTestCase;
use Lenorix\FilamentAutosave\Tests\TestCase;

uses(TestCase::class)->in(__DIR__.'/Unit');
uses(IntegrationTestCase::class)->in(__DIR__.'/Integration');
uses(BrowserTestCase::class)->in(__DIR__.'/Browser');

// A cold panel render compiles Filament's views; give Playwright actions
// room for that instead of the 5 s default.
pest()->browser()->timeout(15_000);
