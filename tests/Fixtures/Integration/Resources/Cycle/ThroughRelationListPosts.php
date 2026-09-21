<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Cycle;

use Filament\Resources\Pages\ListRecords;

class ThroughRelationListPosts extends ListRecords
{
    protected static string $resource = ThroughRelationPostResource::class;
}
