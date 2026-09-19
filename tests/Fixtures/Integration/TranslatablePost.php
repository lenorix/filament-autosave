<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Translatable\HasTranslations;

class TranslatablePost extends Model
{
    use HasTranslations;

    protected $table = 'translatable_posts';

    protected $fillable = ['title', 'slug'];

    public array $translatable = ['title'];

    public $timestamps = false;

    public function items(): HasMany
    {
        return $this->hasMany(PostItem::class, 'post_id');
    }
}
