<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\PollRelations;

use Filament\Resources\Pages\EditRecord;
use Lenorix\FilamentAutosave\HasAutosave;

class PollEditPost extends EditRecord
{
    use HasAutosave;

    protected static string $resource = PollPostResource::class;
}
