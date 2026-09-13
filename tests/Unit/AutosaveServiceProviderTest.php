<?php

use Illuminate\Support\ServiceProvider;
use Lenorix\FilamentAutosave\AutosaveServiceProvider;

test('publishing covers only the configuration, translations, and Blade views', function () {
    $paths = ServiceProvider::pathsToPublish(AutosaveServiceProvider::class);
    $sources = array_map('realpath', array_keys($paths));
    $root = dirname(__DIR__, 2);

    expect($sources)->toHaveCount(3)->toContain(
        realpath($root.'/config/filament-autosave.php'),
        realpath($root.'/resources/lang'),
        realpath($root.'/resources/views'),
    );

    foreach ($paths as $destination) {
        expect(str_starts_with($destination, public_path().DIRECTORY_SEPARATOR))->toBeFalse();
    }
});
