<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\UploadPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\PostResource;

/**
 * Same JSON repeater as {@see RowMediaPostResource}, but every row shares one
 * collection: saving one row would delete the others' media, so it must stay blocked.
 */
class SharedRowMediaPostResource extends PostResource
{
    protected static ?string $model = UploadPost::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            Repeater::make('settings')->schema([
                Hidden::make('uuid')->default(fn (): string => (string) Str::uuid()),
                TextInput::make('label'),
                SpatieMediaLibraryFileUpload::make('images')
                    ->multiple()
                    ->disk('public')
                    ->maxSize(10)
                    ->collection('gallery'),
            ]),
        ]);
    }
}
