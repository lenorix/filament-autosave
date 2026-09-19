<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Upload;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;

class SecretUploadPostResource extends UploadPostResource
{
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title'),
            Group::make([
                TextInput::make('secret')->password(),
                FileUpload::make('files')->multiple()->disk('public'),
            ])->statePath('settings'),
        ]);
    }
}
