<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Forms\Components\MorphToSelect;
use Filament\Forms\Components\MorphToSelect\Type;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;

class MorphToPostResource extends Resource
{
    protected static ?string $model = Post::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title'),
            MorphToSelect::make('featured')
                ->types([
                    Type::make(Category::class)->titleAttribute('name'),
                    Type::make(Author::class)->titleAttribute('name'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => MorphToListPosts::route('/'),
            'edit' => MorphToEditPost::route('/{record}/edit'),
        ];
    }
}
