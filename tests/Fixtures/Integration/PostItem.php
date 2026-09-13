<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PostItem extends Model
{
    protected $fillable = ['post_id', 'category_id', 'label', 'position'];

    public $timestamps = false;

    public function subitems(): HasMany
    {
        return $this->hasMany(PostSubItem::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
