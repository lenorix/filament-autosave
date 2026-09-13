<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Resources\Pages\ListRecords;

class BuilderListPosts extends ListRecords
{
    protected static string $resource = BuilderPostResource::class;
}
