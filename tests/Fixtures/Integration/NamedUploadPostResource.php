<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class NamedUploadPostResource extends UploadPostResource
{
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title'),
            FileUpload::make('slug')->disk('public')->storeFileNamesIn('title'),
        ]);
    }
}
