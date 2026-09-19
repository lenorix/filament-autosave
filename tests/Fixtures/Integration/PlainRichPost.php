<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

class PlainRichPost extends Post
{
    protected $table = 'posts';

    protected $fillable = ['title', 'body'];
}
