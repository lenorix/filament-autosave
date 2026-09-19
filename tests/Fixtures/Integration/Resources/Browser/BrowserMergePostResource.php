<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Browser;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;

/**
 * A Post resource whose body is merged word by word. The body's length
 * limit gives the browser tests a field that stays dirty (pending) while
 * polling runs, which is when a poll hands the other editor's value over.
 */
class BrowserMergePostResource extends Resource
{
    protected static ?string $model = Post::class;

    protected static ?string $slug = 'merge-posts';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            Textarea::make('body')->rules(['max:80']),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => BrowserMergeListPosts::route('/'),
            'edit' => BrowserMergeEditPost::route('/{record}/edit'),
        ];
    }
}
