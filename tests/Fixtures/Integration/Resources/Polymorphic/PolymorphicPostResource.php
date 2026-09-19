<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Polymorphic;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;

class PolymorphicPostResource extends Resource
{
    protected static ?string $model = Post::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title'),
            Repeater::make('comments')
                ->relationship('comments')
                ->schema([TextInput::make('body')]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => PolymorphicListPosts::route('/'),
            'edit' => PolymorphicEditPost::route('/{record}/edit'),
        ];
    }
}
