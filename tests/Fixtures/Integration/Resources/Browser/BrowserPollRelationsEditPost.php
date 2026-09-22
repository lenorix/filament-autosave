<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Browser;

use Filament\Resources\Pages\EditRecord;
use Lenorix\FilamentAutosave\HasAutosave;

class BrowserPollRelationsEditPost extends EditRecord
{
    use HasAutosave;

    protected static string $resource = BrowserPollRelationsPostResource::class;
}
