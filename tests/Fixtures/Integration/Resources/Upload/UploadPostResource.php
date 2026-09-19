<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\UploadPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\PostResource;

class UploadPostResource extends PostResource
{
    protected static ?string $model = UploadPost::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            FileUpload::make('settings')->multiple()->reorderable()->disk('public')->maxSize(10),
            SpatieMediaLibraryFileUpload::make('gallery')->multiple()->reorderable()->disk('public')->maxSize(10),
        ]);
    }
}
