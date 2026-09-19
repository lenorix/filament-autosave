<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PostSubItem extends Model
{
    protected $fillable = ['post_item_id', 'label'];

    public $timestamps = false;

    public function subsubitems(): HasMany
    {
        return $this->hasMany(PostSubSubItem::class);
    }
}
