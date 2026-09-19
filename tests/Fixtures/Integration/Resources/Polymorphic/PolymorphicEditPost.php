<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Polymorphic;

use Filament\Resources\Pages\EditRecord;
use Lenorix\FilamentAutosave\HasAutosave;

class PolymorphicEditPost extends EditRecord
{
    use HasAutosave;

    protected static string $resource = PolymorphicPostResource::class;
}
