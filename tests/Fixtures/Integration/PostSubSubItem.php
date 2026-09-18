<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Illuminate\Database\Eloquent\Model;

class PostSubSubItem extends Model
{
    protected $fillable = ['post_sub_item_id', 'label'];

    public $timestamps = false;
}
