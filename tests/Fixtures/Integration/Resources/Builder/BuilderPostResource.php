<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Builder;

use Filament\Forms\Components\Builder;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;

class BuilderPostResource extends Resource
{
    protected static ?string $model = Post::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Builder::make('settings')->blocks([
                Builder\Block::make('text')->schema([
                    TextInput::make('content'),
                ]),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => BuilderListPosts::route('/'),
            'edit' => BuilderEditPost::route('/{record}/edit'),
        ];
    }
}
