<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Resources\Pages\EditRecord;
use Lenorix\FilamentAutosave\HasAutosave;

class RelationshipEditPost extends EditRecord
{
    use HasAutosave;

    protected static string $resource = RelationshipPostResource::class;
}
