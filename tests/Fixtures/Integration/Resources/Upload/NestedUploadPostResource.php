<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;

class NestedUploadPostResource extends UploadPostResource
{
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            Group::make([
                TextInput::make('caption'),
                FileUpload::make('files')->multiple()->disk('public')->maxSize(10),
            ])->statePath('settings'),
        ]);
    }
}
