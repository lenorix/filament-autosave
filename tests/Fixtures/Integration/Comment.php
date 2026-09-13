<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Comment extends Model
{
    protected $fillable = ['body', 'commentable_type', 'commentable_id'];

    public $timestamps = false;

    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}
