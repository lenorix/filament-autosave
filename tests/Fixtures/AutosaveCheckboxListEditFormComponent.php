<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\HasAutosave;
use Livewire\Component;

class AutosaveCheckboxListEditFormComponent extends Component implements HasSchemas
{
    use HasAutosave;
    use InteractsWithSchemas;

    public ?array $data = [];

    /** @var array<string, mixed> */
    public array $written = [];

    public function mount(): void
    {
        $this->form->fill();
        $this->mountHasAutosave();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title'),
                CheckboxList::make('tags')
                    ->options(['a' => 'A', 'b' => 'B', 'c' => 'C']),
            ])
            ->statePath('data');
    }

    public function getRecord(): object
    {
        return new class
        {
            public bool $exists = true;

            /**
             * @param  array<int, string>  $keys
             * @return array<string, mixed>
             */
            public function only(array $keys): array
            {
                return [];
            }

            /** @return array<string, mixed> */
            public function getAttributes(): array
            {
                return ['title' => null, 'tags' => null];
            }

            public function getKey(): int
            {
                return 1;
            }

            public function refresh(): static
            {
                return $this;
            }
        };
    }

    /** @param  array<string, mixed>  $data */
    public function handleRecordUpdate(object $record, array $data): object
    {
        $this->written = $data;

        return $record;
    }

    public function render(): string
    {
        return <<<'BLADE'
            <div></div>
            BLADE;
    }
}
