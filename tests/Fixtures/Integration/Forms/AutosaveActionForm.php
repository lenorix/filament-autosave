<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\FormsComponent;
use Lenorix\FilamentAutosave\HasAutosaveForForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;

class AutosaveActionForm extends FormsComponent
{
    use HasAutosaveForForm;

    public Post $record;

    public function mount(Post $record): void
    {
        $this->record = $record;
        $this->mountAction('edit');
        $this->mountHasAutosaveForForm();
    }

    public function editAction(): Action
    {
        return Action::make('edit')
            ->record($this->record)
            ->schema([TextInput::make('title')]);
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
