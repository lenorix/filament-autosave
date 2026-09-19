<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\HasAutosaveForForm;
use Livewire\Component;

class AutosaveColumnsRecordForm extends Component implements HasSchemas
{
    use HasAutosaveForForm;
    use InteractsWithSchemas;

    public Post $record;

    public ?array $data = [];

    public function mount(Post $record): void
    {
        $this->record = $record;
        $this->form->fill($record->attributesToArray());
        $this->mountHasAutosaveForForm();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->model($this->record)
            ->components([
                TextInput::make('title'),
                TextInput::make('slug'),
            ])
            ->statePath('data');
    }

    public function getRecord(): Post
    {
        return $this->record;
    }

    protected function getAutosaveFormContext(): string
    {
        return 'columns:'.$this->record->getKey();
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
