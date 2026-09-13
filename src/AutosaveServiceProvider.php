<?php

namespace Lenorix\FilamentAutosave;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class AutosaveServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-autosave';

    public function packageRegistered(): void
    {
        $this->app->singleton(AutosaveStore::class);
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile()
            ->hasViews()
            ->hasTranslations();
    }
}
