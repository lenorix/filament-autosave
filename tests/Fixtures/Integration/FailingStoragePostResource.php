<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use RuntimeException;

class FailingStoragePostResource extends UploadPostResource
{
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title'),
            FileUpload::make('settings')
                ->disk('public')
                ->saveUploadedFileUsing(fn (): never => throw new RuntimeException('storage unavailable')),
        ]);
    }
}
