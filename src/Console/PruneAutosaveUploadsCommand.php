<?php

namespace Lenorix\FilamentAutosave\Console;

use Illuminate\Console\Command;
use Lenorix\FilamentAutosave\AutosaveUploadLedger;

class PruneAutosaveUploadsCommand extends Command
{
    protected $signature = 'filament-autosave:prune-uploads';

    protected $description = 'Remove upload files left by interrupted autosave requests';

    public function handle(AutosaveUploadLedger $ledger): int
    {
        $count = $ledger->prune();
        $this->info("Removed {$count} stale autosave upload entr".($count === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
