<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class EventsTitleOnlyRecordForm extends AutosaveUploadRecordForm
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->model($this->record)
            ->components([TextInput::make('title')])
            ->statePath('data');
    }
}
