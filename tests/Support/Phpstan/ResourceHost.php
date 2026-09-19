<?php

namespace Lenorix\FilamentAutosave\Tests\Support\Phpstan;

use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;

/** Analysis-only resource referenced by the page hosts. Never loaded at runtime. */
final class ResourceHost extends Resource
{
    protected static ?string $model = Model::class;
}
