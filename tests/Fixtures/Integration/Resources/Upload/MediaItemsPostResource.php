<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\MediaItemsPost;

class MediaItemsPostResource extends UploadPostResource
{
    protected static ?string $model = MediaItemsPost::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            Repeater::make('items')
                ->relationship('items')
                ->schema([
                    TextInput::make('label')->required(),
                    FileUpload::make('attachment')->disk('public')->maxSize(10),
                    SpatieMediaLibraryFileUpload::make('images')->multiple()->reorderable()->disk('public')->maxSize(10),
                ]),
        ]);
    }
}
