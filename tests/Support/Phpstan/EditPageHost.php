<?php

namespace Lenorix\FilamentAutosave\Tests\Support\Phpstan;

use Filament\Resources\Pages\EditRecord;
use Lenorix\FilamentAutosave\HasAutosave;

/**
 * Analysis-only host: gives phpstan a concrete class using the Edit-page
 * trait so the trait body is analysed. Never loaded at runtime.
 */
final class EditPageHost extends EditRecord
{
    use HasAutosave;

    protected static string $resource = ResourceHost::class;
}
