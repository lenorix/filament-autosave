<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\HasAutosaveForCreate;
use Livewire\Component;

class AutosaveMultiOptionCreateFormComponent extends Component implements HasSchemas
{
    use HasAutosaveForCreate;
    use InteractsWithSchemas;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
        $this->mountHasAutosaveForCreate();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title'),
                CheckboxList::make('tags')
                    ->options(['a' => 'A', 'b' => 'B', 'c' => 'C']),
                ToggleButtons::make('perms')
                    ->multiple()
                    ->options(['r' => 'Read', 'w' => 'Write', 'x' => 'Exec']),
                Select::make('roles')
                    ->multiple()
                    ->options(['admin' => 'Admin', 'editor' => 'Editor', 'viewer' => 'Viewer']),
            ])
            ->statePath('data');
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div></div>
            BLADE;
    }
}
