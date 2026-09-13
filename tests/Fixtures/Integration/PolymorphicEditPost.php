<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Resources\Pages\EditRecord;
use Lenorix\FilamentAutosave\HasAutosave;

class PolymorphicEditPost extends EditRecord
{
    use HasAutosave;

    protected static string $resource = PolymorphicPostResource::class;
}
