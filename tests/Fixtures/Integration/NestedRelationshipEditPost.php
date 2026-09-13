<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Resources\Pages\EditRecord;
use Lenorix\FilamentAutosave\HasAutosave;

class NestedRelationshipEditPost extends EditRecord
{
    use HasAutosave;

    protected static string $resource = NestedRelationshipPostResource::class;
}
