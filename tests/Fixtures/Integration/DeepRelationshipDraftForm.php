<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\HasAutosaveForForm;
use Livewire\Component;

class DeepRelationshipDraftForm extends Component implements HasSchemas
{
    use HasAutosaveForForm;
    use InteractsWithSchemas;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
        $this->mountHasAutosaveForForm();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title'),
                Repeater::make('items')->schema([
                    TextInput::make('label'),
                    Repeater::make('subitems')->schema([
                        TextInput::make('label'),
                        Repeater::make('subsubitems')->schema([
                            TextInput::make('label'),
                        ]),
                    ]),
                ]),
            ])
            ->statePath('data');
    }

    protected function getAutosaveFormContext(): string
    {
        return 'deep-draft';
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
