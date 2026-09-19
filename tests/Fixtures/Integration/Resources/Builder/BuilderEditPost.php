<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Builder;

use Filament\Resources\Pages\EditRecord;
use Lenorix\FilamentAutosave\HasAutosave;

class BuilderEditPost extends EditRecord
{
    use HasAutosave;

    protected static string $resource = BuilderPostResource::class;
}
