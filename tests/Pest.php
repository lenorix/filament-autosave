<?php

require __DIR__.'/Support/helpers.php';

use Lenorix\FilamentAutosave\Tests\IntegrationTestCase;
use Lenorix\FilamentAutosave\Tests\TestCase;

uses(TestCase::class)->in(__DIR__.'/Unit');
uses(IntegrationTestCase::class)->in(__DIR__.'/Integration');
