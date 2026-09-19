<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\MorphTo;

use Filament\Resources\Pages\EditRecord;
use Lenorix\FilamentAutosave\HasAutosave;

class MorphToEditPost extends EditRecord
{
    use HasAutosave;

    protected static string $resource = MorphToPostResource::class;
}
