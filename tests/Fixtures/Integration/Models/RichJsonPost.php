<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models;

/** A Post whose rich body is stored as Tiptap JSON. */
class RichJsonPost extends Post
{
    protected $table = 'posts';

    protected $casts = ['body' => 'array'];
}
