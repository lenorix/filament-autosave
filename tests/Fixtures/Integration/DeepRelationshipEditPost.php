<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Resources\Pages\EditRecord;
use Lenorix\FilamentAutosave\HasAutosave;

class DeepRelationshipEditPost extends EditRecord
{
    use HasAutosave;

    protected static string $resource = DeepRelationshipPostResource::class;
}
