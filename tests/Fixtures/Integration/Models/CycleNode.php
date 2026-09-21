<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Self-referential: children() points back at CycleNode, so a schema that
 * nests this relationship inside itself renders a cyclic relation *type*
 * graph (parent -> child -> parent), not just a deep one.
 */
class CycleNode extends Model
{
    protected $fillable = ['parent_id', 'label'];

    public function children(): HasMany
    {
        return $this->hasMany(CycleNode::class, 'parent_id');
    }
}
