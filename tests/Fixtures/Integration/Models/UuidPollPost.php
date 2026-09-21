<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UuidPollPost extends Model
{
    protected $table = 'uuid_poll_posts';

    protected $fillable = ['id', 'title'];

    public $incrementing = false;

    protected $keyType = 'string';

    public function items(): HasMany
    {
        return $this->hasMany(UuidPollItem::class)->orderBy('label');
    }
}
