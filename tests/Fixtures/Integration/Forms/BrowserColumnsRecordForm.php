<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Lenorix\FilamentAutosave\HasAutosaveForForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;
use Livewire\Component;

/**
 * A record-backed generic form rendered for real: the schema plus the
 * indicator in `form` mode, exactly as the README tells a host to include it.
 * Mirrors {@see AutosaveColumnsRecordForm}, which renders nothing.
 */
class BrowserColumnsRecordForm extends Component implements HasSchemas
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

    /** A server-side change to form state, the kind an action or afterStateUpdated makes. */
    public function generateSlug(): void
    {
        $this->data['slug'] = str($this->data['title'] ?? '')->slug()->toString();
    }

    protected function getAutosaveFormContext(): string
    {
        return 'browser-columns:'.$this->record->getKey();
    }

    /** @return array<int, string> */
    protected function autosaveMergeFields(): ?array
    {
        return ['title'];
    }

    public function render(): View
    {
        return view('autosave-fixtures::columns-form');
    }
}
