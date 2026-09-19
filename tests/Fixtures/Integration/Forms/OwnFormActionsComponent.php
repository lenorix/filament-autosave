<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms;

use Filament\Forms\Components\TextInput;
use Filament\Forms\FormsComponent;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\HasAutosaveForForm;

class OwnFormActionsComponent extends FormsComponent
{
    use HasAutosaveForForm;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill();
        $this->mountHasAutosaveForForm();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('title')])->statePath('data');
    }

    protected function getAutosaveFormContext(): string
    {
        return 'own-form';
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
