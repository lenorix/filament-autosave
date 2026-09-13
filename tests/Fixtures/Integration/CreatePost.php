<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Resources\Pages\CreateRecord;
use Lenorix\FilamentAutosave\HasAutosaveForCreate;

class CreatePost extends CreateRecord
{
    use HasAutosaveForCreate;

    protected static string $resource = PostResource::class;
}
