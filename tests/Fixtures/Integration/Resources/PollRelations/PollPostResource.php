<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\PollRelations;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\PollPost;

class PollPostResource extends Resource
{
    protected static ?string $model = PollPost::class;

    protected static ?string $slug = 'poll-posts';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title'),
            FileUpload::make('attachment')->disk('public'),
            SpatieMediaLibraryFileUpload::make('gallery')->multiple()->disk('public'),
            Repeater::make('items')
                ->relationship('items')
                ->orderColumn('position')
                ->schema([
                    TextInput::make('label'),
                ]),
            CheckboxList::make('authors')->relationship('authors', 'name'),
            Repeater::make('notes')
                ->relationship('notes')
                ->schema([
                    Textarea::make('body'),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => PollListPosts::route('/'),
            'edit' => PollEditPost::route('/{record}/edit'),
        ];
    }
}
