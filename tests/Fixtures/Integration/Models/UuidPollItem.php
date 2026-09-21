<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models;

use Illuminate\Database\Eloquent\Model;

class UuidPollItem extends Model
{
    protected $table = 'uuid_poll_items';

    protected $fillable = ['id', 'uuid_poll_post_id', 'label', 'created_at', 'updated_at'];

    public $incrementing = false;

    protected $keyType = 'string';
}
