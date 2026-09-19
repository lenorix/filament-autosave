<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\HasAutosaveForForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\PollPost;
use Livewire\Component;

/** A record-backed generic form with the relationship fields of PollPostResource. */
class PollRelationsRecordForm extends Component implements HasSchemas
{
    use HasAutosaveForForm;
    use InteractsWithSchemas;

    public PollPost $record;

    public ?array $data = [];

    public function mount(PollPost $record): void
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
                Repeater::make('items')
                    ->relationship('items')
                    ->orderColumn('position')
                    ->schema([
                        TextInput::make('label'),
                    ]),
                CheckboxList::make('authors')->relationship('authors', 'name'),
            ])
            ->statePath('data');
    }

    public function getRecord(): PollPost
    {
        return $this->record;
    }

    protected function getAutosaveFormContext(): string
    {
        return 'poll-relations:'.$this->record->getKey();
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
