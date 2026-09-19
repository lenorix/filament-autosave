<?php

test('every shipped locale defines exactly the same translation keys as English', function () {
    $base = dirname(__DIR__, 2).'/resources/lang';
    $english = require $base.'/en/autosave.php';
    $locales = array_map('basename', glob($base.'/*', GLOB_ONLYDIR));

    expect($locales)->toContain('en', 'es');

    foreach ($locales as $locale) {
        $strings = require "{$base}/{$locale}/autosave.php";

        expect(array_keys($strings))->toEqualCanonicalizing(array_keys($english), "locale {$locale}");

        foreach ($strings as $key => $value) {
            expect($value)->toBeString()->not->toBe('', "{$locale}.{$key} is empty");
        }
    }
});

test('the indicator renders through the translator so a locale switch changes its copy', function () {
    app()->setLocale('es');

    $html = view('filament-autosave::autosave-indicator', ['mode' => 'edit', 'debounce' => 500])->render();

    expect($html)->toContain('Cambios sin guardar', 'Deshacer', 'Campos pendientes:')
        ->not->toContain('Changes not yet saved');
});
