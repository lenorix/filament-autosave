<?php

namespace Lenorix\FilamentAutosave;

use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Lenorix\FilamentAutosave\Console\PruneAutosaveUploadsCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class AutosaveServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-autosave';

    public const ASSET_PACKAGE = 'lenorix/filament-autosave';

    public function packageRegistered(): void
    {
        $this->app->singleton(AutosaveStore::class);
        $this->app->singleton(AutosaveExternalUndoManager::class);
        $this->app->singleton(AutosaveUploadLedger::class);
    }

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile()
            ->hasViews()
            ->hasTranslations()
            ->hasCommands([PruneAutosaveUploadsCommand::class]);
    }

    public function packageBooted(): void
    {
        // Filament's compiled theme carries no generic utilities, so the
        // indicator's helper text ships its own stylesheet as a Filament
        // asset; `php artisan filament:assets` publishes it like any other.
        FilamentAsset::register([
            Css::make(static::$name, __DIR__.'/../resources/css/autosave.css'),
        ], package: self::ASSET_PACKAGE);
    }
}
