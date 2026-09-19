<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models;

use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class MediaPostItem extends PostItem implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'post_items';

    protected $fillable = ['post_id', 'label', 'position', 'attachment'];
}
