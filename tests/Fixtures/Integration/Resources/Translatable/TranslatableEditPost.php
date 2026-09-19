<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Translatable;

use Filament\Resources\Pages\EditRecord;
use LaraZeus\SpatieTranslatable\Resources\Pages\EditRecord\Concerns\Translatable;
use Lenorix\FilamentAutosave\HasAutosave;

/** The real translatable concern: its handleRecordUpdate() runs Filament's full save path per extra locale. */
class TranslatableEditPost extends EditRecord
{
    use HasAutosave;
    use Translatable;

    protected static string $resource = TranslatablePostResource::class;
}
