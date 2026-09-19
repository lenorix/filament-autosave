<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Browser;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;

/**
 * A Post whose body is a RichEditor stored as HTML, with file attachments
 * on the public disk (served as a data URI so no request is needed) and a
 * custom block. Path tampering checks are off so a test can insert an
 * already-stored attachment by id.
 */
class BrowserRichMergePostResource extends Resource
{
    public const string IMAGE_SRC = 'data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACwAAAAAAQABAAACAkQBADs=';

    protected static ?string $model = Post::class;

    protected static ?string $slug = 'rich-merge-posts';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            static::editor(),
        ]);
    }

    public static function editor(): RichEditor
    {
        return RichEditor::make('body')
            ->fileAttachmentsDisk('public')
            ->fileAttachmentsVisibility('public')
            ->getFileAttachmentUrlUsing(fn (): string => static::IMAGE_SRC)
            ->preventFileAttachmentPathTampering(false)
            ->customBlocks([BrowserCalloutBlock::class]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => BrowserRichMergeListPosts::route('/'),
            'edit' => BrowserRichMergeEditPost::route('/{record}/edit'),
        ];
    }
}
