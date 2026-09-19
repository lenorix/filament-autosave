<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\RichUpload;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\PlainRichPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\PostResource;

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
