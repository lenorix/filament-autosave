<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Cycle;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;

/**
 * `subitems` is Post::subitems(): HasManyThrough(PostSubItem, via PostItem).
 * Rendering it directly (not nested inside the items Repeater) lets polling
 * be evaluated against a through relation on its own. `CheckboxList` only
 * accepts BelongsToMany; `Select::relationship()` is typed for
 * HasOneOrManyThrough too, so it is the component that can bind one.
 */
class ThroughRelationPostResource extends Resource
{
    protected static ?string $model = Post::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title'),
            Select::make('subitems')->relationship('subitems', 'label')->multiple(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ThroughRelationListPosts::route('/'),
            'edit' => ThroughRelationEditPost::route('/{record}/edit'),
        ];
    }
}
