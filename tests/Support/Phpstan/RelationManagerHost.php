<?php

namespace Lenorix\FilamentAutosave\Tests\Support\Phpstan;

use Filament\Resources\RelationManagers\RelationManager;
use Lenorix\FilamentAutosave\HasAutosaveForRelationManager;

/**
 * Analysis-only host for the relation-manager trait. Never loaded at
 * runtime.
 */
final class RelationManagerHost extends RelationManager
{
    use HasAutosaveForRelationManager;

    protected static string $relationship = 'phpstan';
}
