<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Resources\Pages\ListRecords;

class NestedRelationshipListPosts extends ListRecords
{
    protected static string $resource = NestedRelationshipPostResource::class;
}
