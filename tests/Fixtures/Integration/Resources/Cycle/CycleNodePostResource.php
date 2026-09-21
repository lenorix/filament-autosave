<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Cycle;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\CycleNode;

/**
 * `children` nests the same relationship component inside itself three
 * levels deep (matching the default `poll_relationship_depth`), over a
 * self-referential model: a cyclic relation *type* graph, not just a deep
 * schema of distinct models.
 */
class CycleNodePostResource extends Resource
{
    protected static ?string $model = CycleNode::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('label'),
            Repeater::make('children')
                ->relationship('children')
                ->schema([
                    TextInput::make('label'),
                    Repeater::make('children')
                        ->relationship('children')
                        ->schema([
                            TextInput::make('label'),
                            Repeater::make('children')
                                ->relationship('children')
                                ->schema([TextInput::make('label')]),
                        ]),
                ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => CycleNodeListPosts::route('/'),
            'edit' => CycleNodeEditPost::route('/{record}/edit'),
        ];
    }
}
