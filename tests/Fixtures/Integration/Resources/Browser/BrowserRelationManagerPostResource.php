<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Browser;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\RelationManager\CommentsRelationManager;

/**
 * A whole resource that autosaves top to bottom: the Edit page with
 * `HasAutosave`, and a relation manager whose edit modals autosave with
 * `HasAutosaveForRelationManager` and nothing else.
 */
class BrowserRelationManagerPostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $slug = 'relation-manager-posts';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')]);
    }

    public static function getRelations(): array
    {
        return [CommentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => BrowserRelationManagerListPosts::route('/'),
            'edit' => BrowserRelationManagerEditPost::route('/{record}/edit'),
        ];
    }
}
