<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models;

use Illuminate\Database\Eloquent\Model;

/** No timestamps: the poll cannot fingerprint edits to these rows cheaply. */
class PollNote extends Model
{
    protected $table = 'poll_notes';

    protected $fillable = ['poll_post_id', 'body'];

    public $timestamps = false;
}
