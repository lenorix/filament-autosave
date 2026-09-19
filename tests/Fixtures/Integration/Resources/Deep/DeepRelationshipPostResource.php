<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Deep;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;

class DeepRelationshipPostResource extends Resource
{
    protected static ?string $model = Post::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title'),
            Repeater::make('items')
                ->relationship('items')
                ->schema([
                    TextInput::make('label'),
                    TextInput::make('position')->numeric(),
                    Select::make('category_id')->relationship('category', 'name'),
                    Repeater::make('subitems')
                        ->relationship('subitems')
                        ->schema([
                            TextInput::make('label'),
                            Repeater::make('subsubitems')
                                ->relationship('subsubitems')
                                ->schema([TextInput::make('label')]),
                        ]),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => DeepRelationshipListPosts::route('/'),
            'edit' => DeepRelationshipEditPost::route('/{record}/edit'),
        ];
    }
}
