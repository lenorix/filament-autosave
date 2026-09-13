<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Resources\Pages\ListRecords;

class MorphToListPosts extends ListRecords
{
    protected static string $resource = MorphToPostResource::class;
}
