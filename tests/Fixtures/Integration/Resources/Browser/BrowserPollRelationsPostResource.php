<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Browser;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\PollPost;

/**
 * A PollPost resource whose repeater row is required: blanking it keeps the
 * field dirty forever (validation blocks the write, the hash never
 * acknowledges), the deterministic way this suite already pins a "stays
 * dirty across a poll" scenario. See tests/Browser/AutosavePollingTest.php.
 */
class BrowserPollRelationsPostResource extends Resource
{
    protected static ?string $model = PollPost::class;

    protected static ?string $slug = 'browser-poll-relations-posts';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title'),
            Repeater::make('items')
                ->relationship('items')
                ->orderColumn('position')
                ->schema([
                    TextInput::make('label')->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')]);
    }

    public static function getPages(): array
    {
        return [
            'index' => BrowserPollRelationsListPosts::route('/'),
            'edit' => BrowserPollRelationsEditPost::route('/{record}/edit'),
        ];
    }
}
