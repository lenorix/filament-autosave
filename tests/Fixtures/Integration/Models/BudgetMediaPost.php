<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class BudgetMediaPost extends Post implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'posts';

    public function items(): HasMany
    {
        return $this->hasMany(BudgetMediaPostItem::class, 'post_id');
    }
}
