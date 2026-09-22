<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\HasAutosaveForForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;
use Livewire\Component;

class OrderedRelationshipRecordForm extends Component implements HasSchemas
{
    use HasAutosaveForForm;
    use InteractsWithSchemas;

    public Post $record;

    public ?array $data = [];

    public function mount(Post $record): void
    {
        $this->record = $record;
        $this->form->fill($record->attributesToArray());
        $this->form->loadStateFromRelationships();
        $this->mountHasAutosaveForForm();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->model($this->record)
            ->components([
                Repeater::make('items')
                    ->relationship('items')
                    ->orderColumn('position')
                    ->schema([
                        TextInput::make('label'),
                    ]),
            ])
            ->statePath('data');
    }

    public function getRecord(): Post
    {
        return $this->record;
    }

    protected function getAutosaveFormContext(): string
    {
        return 'ordered:'.$this->record->getKey();
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
