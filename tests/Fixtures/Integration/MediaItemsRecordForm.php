<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class MediaItemsRecordForm extends AutosaveUploadRecordForm
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->model($this->record)
            ->components([
                TextInput::make('title')->required(),
                Repeater::make('items')
                    ->relationship('items')
                    ->schema([
                        TextInput::make('label')->required(),
                        FileUpload::make('attachment')->disk('public')->maxSize(10),
                        SpatieMediaLibraryFileUpload::make('images')->multiple()->disk('public')->maxSize(10),
                    ]),
            ])
            ->statePath('data');
    }
}
