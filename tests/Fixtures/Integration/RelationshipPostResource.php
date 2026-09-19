<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;

class RelationshipPostResource extends Resource
{
    protected static ?string $model = Post::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title'),
            Select::make('category_id')->relationship('category', 'name'),
            CheckboxList::make('authors')->relationship('authors', 'name'),
            Repeater::make('items')
                ->relationship('items')
                ->schema([
                    TextInput::make('label'),
                    TextInput::make('position')->numeric(),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => RelationshipListPosts::route('/'),
            'edit' => RelationshipEditPost::route('/{record}/edit'),
        ];
    }
}
