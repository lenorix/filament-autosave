<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Resources\Pages\ListRecords;

class TranslatableListPosts extends ListRecords
{
    protected static string $resource = TranslatablePostResource::class;
}
