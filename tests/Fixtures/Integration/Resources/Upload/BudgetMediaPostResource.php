<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\BudgetMediaPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\PostResource;

class BudgetMediaPostResource extends PostResource
{
    protected static ?string $model = BudgetMediaPost::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            SpatieMediaLibraryFileUpload::make('gallery')->multiple()->disk('public'),
            Repeater::make('items')
                ->relationship('items')
                ->schema([
                    TextInput::make('label')->required(),
                    SpatieMediaLibraryFileUpload::make('images')->multiple()->disk('public'),
                ]),
        ]);
    }
}
