<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** A Post resource whose `settings` JSON column holds uploaded file paths. */
class BrowserUploadPostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $slug = 'upload-posts';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title'),
            FileUpload::make('settings')->multiple()->disk('public'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => BrowserUploadListPosts::route('/'),
            'edit' => BrowserUploadEditPost::route('/{record}/edit'),
        ];
    }
}
