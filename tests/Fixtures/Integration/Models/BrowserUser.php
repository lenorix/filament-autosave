<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models;

use Illuminate\Foundation\Auth\User;

/** The editor browser tests act as. */
class BrowserUser extends User
{
    protected $table = 'users';

    protected $guarded = [];

    public $timestamps = false;
}
