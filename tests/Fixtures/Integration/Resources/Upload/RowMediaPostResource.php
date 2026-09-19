<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload;

use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\UploadPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\PostResource;

/**
 * A JSON (non-relationship) repeater stored in the `settings` column whose
 * rows each own a Spatie media collection named after a persisted row UUID.
 */
class RowMediaPostResource extends PostResource
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
                    ->collection(fn (Get $get): string => 'row_'.$get('uuid')),
            ]),
        ]);
    }
}
