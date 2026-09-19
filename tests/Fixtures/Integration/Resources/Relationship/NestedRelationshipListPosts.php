<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Relationship;

use Filament\Resources\Pages\ListRecords;

class NestedRelationshipListPosts extends ListRecords
{
    protected static string $resource = NestedRelationshipPostResource::class;
}
