<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Browser;

use Filament\Resources\Pages\EditRecord;
use Lenorix\FilamentAutosave\HasAutosave;

/** Edit page whose rich `body` is merged structurally. */
class BrowserRichMergeEditPost extends EditRecord
{
    use HasAutosave;

    protected static string $resource = BrowserRichMergePostResource::class;

    /** @return array<int, string> */
    protected function autosaveMergeFields(): ?array
    {
        return ['body'];
    }
}
