<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Browser;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\RichJsonPost;

/** The same editor as {@see BrowserRichMergePostResource}, stored as JSON. */
class BrowserRichJsonMergePostResource extends BrowserRichMergePostResource
{
    protected static ?string $model = RichJsonPost::class;

    protected static ?string $slug = 'rich-json-merge-posts';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            static::editor()->json(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => BrowserRichJsonMergeListPosts::route('/'),
            'edit' => BrowserRichJsonMergeEditPost::route('/{record}/edit'),
        ];
    }
}
