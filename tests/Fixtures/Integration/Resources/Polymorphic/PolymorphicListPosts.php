<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Polymorphic;

use Filament\Resources\Pages\ListRecords;

class PolymorphicListPosts extends ListRecords
{
    protected static string $resource = PolymorphicPostResource::class;
}
