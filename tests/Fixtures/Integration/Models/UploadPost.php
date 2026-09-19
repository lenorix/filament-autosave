<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models;

use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class UploadPost extends Post implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'posts';
}
