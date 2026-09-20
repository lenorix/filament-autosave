<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\RelationManager;

use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Lenorix\FilamentAutosave\HasAutosaveForRelationManager;

/**
 * The whole point of the trait: a relation manager that autosaves its edit
 * modals with no wiring at all beyond the `use` statement. Its create modal
 * needs the one line every `HasAutosaveForCreate`/`HasAutosaveForForm`
 * consumer already needs: clearing the draft once the row exists.
 */
class CommentsRelationManager extends RelationManager
{
    use HasAutosaveForRelationManager;

    protected static string $relationship = 'comments';

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('body')])
            ->headerActions([
                CreateAction::make()
                    ->schema([TextInput::make('body')])
                    ->after(fn (CommentsRelationManager $livewire) => $livewire->clearAutosaveDraft()),
            ])
            ->actions([
                EditAction::make()
                    ->schema([TextInput::make('body')])
                    ->extraAttributes(['data-testid' => 'edit-comment']),
            ]);
    }

    /** Filament's `fillForm()` testing helper cannot target a mounted action's schema by name. */
    public function fillMountedAction(array $data): void
    {
        $this->getMountedActionSchema()->fill($data);
    }
}
