<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\RichUpload;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\RichUploadPost;

class RichUploadPostResource extends Resource
{
    protected static ?string $model = RichUploadPost::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('title'), RichEditor::make('body')->json()]);
    }

    public static function getPages(): array
    {
        return ['index' => RichUploadListPosts::route('/'), 'edit' => RichUploadEditPost::route('/{record}/edit')];
    }
}
