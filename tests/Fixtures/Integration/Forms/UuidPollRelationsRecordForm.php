<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms;

use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\HasAutosaveForForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\UuidPollPost;
use Livewire\Component;

/** A relation-backed form whose related rows use UUID primary keys. */
class UuidPollRelationsRecordForm extends Component implements HasSchemas
{
    use HasAutosaveForForm;
    use InteractsWithSchemas;

    public UuidPollPost $record;

    public ?array $data = [];

    public function mount(UuidPollPost $record): void
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
                    ->schema([
                        TextInput::make('label'),
                    ]),
            ])
            ->statePath('data');
    }

    public function getRecord(): UuidPollPost
    {
        return $this->record;
    }

    protected function getAutosaveFormContext(): string
    {
        return 'uuid-poll-relations:'.$this->record->getKey();
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
