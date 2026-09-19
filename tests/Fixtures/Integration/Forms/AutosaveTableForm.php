<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Tables\TableComponent;
use Illuminate\Database\Eloquent\Builder;
use Lenorix\FilamentAutosave\HasAutosaveForForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;

class AutosaveTableForm extends TableComponent
{
    use HasAutosaveForForm;

    protected function getTableQuery(): Builder
    {
        return Post::query();
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('title')])
            ->actions([
                Action::make('edit')
                    ->schema([
                        TextInput::make('title'),
                    ])
                    ->action(fn (array $data, Post $record): bool => $record->update($data)),
            ]);
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
