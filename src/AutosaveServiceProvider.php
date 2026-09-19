<?php

namespace Lenorix\FilamentAutosave;

use Lenorix\FilamentAutosave\Console\PruneAutosaveUploadsCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class AutosaveServiceProvider extends PackageServiceProvider
{
    public static string $name = 'filament-autosave';

    public function packageRegistered(): void
    {
        $this->app->singleton(AutosaveStore::class);
        $this->app->singleton(AutosaveExternalUndoManager::class);
        $this->app->singleton(AutosaveUploadLedger::class);
        $this->app->singleton(AutosaveMediaJournal::class);
    }

    public function packageBooted(): void
    {
        // Boot-time so it precedes any listener the host adds afterwards.
        if (AutosaveMediaJournal::mediaModel() !== null) {
            $this->app->make(AutosaveMediaJournal::class)->listen();
        }
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
}
