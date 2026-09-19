<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\RichUpload;

use Filament\Resources\Pages\EditRecord;
use Lenorix\FilamentAutosave\HasAutosave;

class RichUploadEditPost extends EditRecord
{
    use HasAutosave;

    protected static string $resource = RichUploadPostResource::class;
}
