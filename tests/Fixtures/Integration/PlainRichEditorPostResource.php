<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PlainRichEditorPostResource extends PostResource
{
    protected static ?string $model = PlainRichPost::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            RichEditor::make('body'),
        ]);
    }
}
