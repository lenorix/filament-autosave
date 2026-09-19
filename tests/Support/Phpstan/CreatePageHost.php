<?php

namespace Lenorix\FilamentAutosave\Tests\Support\Phpstan;

use Filament\Resources\Pages\CreateRecord;
use Lenorix\FilamentAutosave\HasAutosaveForCreate;

/** Analysis-only host for the Create-page trait. Never loaded at runtime. */
final class CreatePageHost extends CreateRecord
{
    use HasAutosaveForCreate;

    protected static string $resource = ResourceHost::class;
}
