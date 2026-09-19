<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Translatable;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use LaraZeus\SpatieTranslatable\Resources\Concerns\Translatable;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\TranslatablePost;

class TranslatablePostResource extends Resource
{
    use Translatable;

    protected static ?string $model = TranslatablePost::class;

    protected static ?string $slug = 'translatable-posts';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title'),
            TextInput::make('slug'),
            Repeater::make('items')
                ->relationship('items')
                ->schema([
                    TextInput::make('label'),
                    TextInput::make('position')->numeric(),
                    Repeater::make('subitems')
                        ->relationship('subitems')
                        ->schema([TextInput::make('label')]),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => TranslatableListPosts::route('/'),
            'edit' => TranslatableEditPost::route('/{record}/edit'),
        ];
    }
}
