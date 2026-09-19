<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models;

class PlainRichPost extends Post
{
    protected $table = 'posts';

    protected $fillable = ['title', 'body'];
}
