<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Lenorix\FilamentAutosave\HasAutosaveForForm;

class AutosaveCommentsRelationManager extends RelationManager
{
    use HasAutosaveForForm;

    protected static string $relationship = 'comments';

    public ?array $data = [];

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('body')])
            ->actions([
                Action::make('edit')
                    ->schema([
                        TextInput::make('body'),
                    ])
                    ->action(fn (array $data, Comment $record): bool => $record->update($data)),
            ]);
    }

    protected function resolveAutosaveForm(): ?object
    {
        try {
            return $this->getMountedActionSchema();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function getAutosaveFormContext(): string
    {
        return 'owner:'.($this->ownerRecord->getKey() ?? 'new');
    }

    public function fillMountedEdit(array $data): void
    {
        $this->getMountedActionSchema()->fill($data);
    }
}
