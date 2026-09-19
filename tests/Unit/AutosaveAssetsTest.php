<?php

use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Lenorix\FilamentAutosave\AutosaveServiceProvider;

test('the indicator stylesheet is registered as a Filament asset of this package', function () {
    // getStyles() flattens to a list, so look the asset up by id.
    $css = collect(FilamentAsset::getStyles([AutosaveServiceProvider::ASSET_PACKAGE]))
        ->first(fn (Css $asset): bool => $asset->getId() === AutosaveServiceProvider::$name);

    expect($css)->toBeInstanceOf(Css::class)
        ->and($css->getPackage())->toBe(AutosaveServiceProvider::ASSET_PACKAGE)
        ->and(is_file($css->getPath()))->toBeTrue()
        ->and($css->getPublicPath())->toEndWith('css/lenorix/filament-autosave/filament-autosave.css');
});

test('the stylesheet styles every helper class the indicator uses and none of the old utilities remain', function () {
    $css = file_get_contents(__DIR__.'/../../resources/css/autosave.css');
    $view = file_get_contents(__DIR__.'/../../resources/views/autosave-indicator.blade.php');

    foreach (['fi-autosave-stack', 'fi-autosave-note', 'fi-autosave-list'] as $class) {
        expect($view)->toContain("class=\"{$class}\"")
            ->and($css)->toContain(".{$class}");
    }

    expect($css)->toContain('.dark .fi-autosave-indicator')
        ->and($view)->not->toContain('text-gray-')->not->toContain('dark:')->not->toContain('flex-col');
});
