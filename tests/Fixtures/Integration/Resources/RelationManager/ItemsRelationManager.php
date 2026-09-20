<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\RelationManager;

use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Lenorix\FilamentAutosave\HasAutosaveForRelationManager;

/**
 * A second relation manager on the same owner, with an action of the same
 * name: only the relationship tells the two autosave scopes apart.
 */
class ItemsRelationManager extends RelationManager
{
    use HasAutosaveForRelationManager;

    protected static string $relationship = 'items';

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('label')])
            ->actions([
                EditAction::make()->schema([TextInput::make('label')]),
            ]);
    }
}
