<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models;

use Illuminate\Database\Eloquent\Model;

class PollItem extends Model
{
    protected $table = 'poll_items';

    protected $fillable = ['poll_post_id', 'label', 'position'];
}
