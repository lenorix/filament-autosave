<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A timestamped record whose relations mix the cheap-detector cases: children
 * with timestamps, a pivot with timestamps, and children without any.
 */
class PollPost extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'poll_posts';

    protected $fillable = ['title', 'attachment'];

    public function items(): HasMany
    {
        return $this->hasMany(PollItem::class)->orderBy('position');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(PollNote::class);
    }

    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(Author::class, 'author_poll_post')->withPivot('role')->withTimestamps();
    }
}
