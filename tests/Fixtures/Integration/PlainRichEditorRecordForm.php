<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PlainRichEditorRecordForm extends AutosaveUploadRecordForm
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->model($this->record)
            ->components([
                TextInput::make('title'),
                RichEditor::make('body'),
            ])
            ->statePath('data');
    }
}
