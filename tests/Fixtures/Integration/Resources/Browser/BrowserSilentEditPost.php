<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Browser;

use Filament\Resources\Pages\EditRecord;
use Lenorix\FilamentAutosave\HasAutosave;

/**
 * An Edit page whose `autosave()` returns without dispatching any status:
 * the reply a misbehaving override or a swallowed exception would produce.
 */
class BrowserSilentEditPost extends EditRecord
{
    use HasAutosave;

    protected static string $resource = BrowserSilentPostResource::class;

    public function autosave(array $mergePatches = []): void
    {
        // Nothing written, nothing said.
    }
}
