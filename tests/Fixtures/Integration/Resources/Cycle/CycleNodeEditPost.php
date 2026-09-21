<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Cycle;

use Filament\Resources\Pages\EditRecord;
use Lenorix\FilamentAutosave\HasAutosave;

class CycleNodeEditPost extends EditRecord
{
    use HasAutosave;

    protected static string $resource = CycleNodePostResource::class;
}
