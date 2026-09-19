<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;

class NestedRelationshipPostResource extends RelationshipPostResource
{
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Group::make([
                Repeater::make('items')
                    ->relationship('items')
                    ->schema([
                        TextInput::make('label'),
                        TextInput::make('position')->numeric(),
                    ]),
            ])->statePath('settings'),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => NestedRelationshipListPosts::route('/'),
            'edit' => NestedRelationshipEditPost::route('/{record}/edit'),
        ];
    }
}
