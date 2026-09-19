<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Deep;

use Filament\Resources\Pages\ListRecords;

class DeepRelationshipListPosts extends ListRecords
{
    protected static string $resource = DeepRelationshipPostResource::class;
}
