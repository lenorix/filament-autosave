<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Illuminate\Database\Eloquent\Model;

class PostSubItem extends Model
{
    protected $fillable = ['post_item_id', 'label'];

    public $timestamps = false;
}
