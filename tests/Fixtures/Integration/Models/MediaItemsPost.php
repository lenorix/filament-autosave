<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class MediaItemsPost extends UploadPost
{
    public function items(): HasMany
    {
        return $this->hasMany(MediaPostItem::class, 'post_id');
    }
}
