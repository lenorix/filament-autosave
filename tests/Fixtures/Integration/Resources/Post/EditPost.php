<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post;

use Filament\Resources\Pages\EditRecord;
use Lenorix\FilamentAutosave\HasAutosave;

class EditPost extends EditRecord
{
    use HasAutosave;

    protected static string $resource = PostResource::class;
}
