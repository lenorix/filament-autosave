<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Post extends Model
{
    protected $fillable = ['title', 'slug', 'body', 'settings', 'category_id', 'featured_type', 'featured_id'];

    protected $casts = ['settings' => 'array'];

    public $timestamps = false;

    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(Author::class)->withPivot('role');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function featured(): MorphTo
    {
        return $this->morphTo();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PostItem::class)->orderBy('position');
    }

    public function subitems(): HasManyThrough
    {
        return $this->hasManyThrough(PostSubItem::class, PostItem::class);
    }
}
