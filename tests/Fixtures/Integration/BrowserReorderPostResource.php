<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** A Post resource with a relationship repeater ordered through `position`. */
class BrowserReorderPostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $slug = 'reorder-posts';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title'),
            Repeater::make('items')
                ->relationship('items')
                ->orderColumn('position')
                ->reorderableWithButtons()
                ->schema([
                    TextInput::make('label'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => BrowserReorderListPosts::route('/'),
            'edit' => BrowserReorderEditPost::route('/{record}/edit'),
        ];
    }
}
