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

    /** @var array<int, string> */
    public array $actionLifecycle = [];

    public bool $actionRedirect = false;

    public bool $actionMutate = false;

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
            ->schema([TextInput::make('title')])
            ->beforeFormValidated(fn () => $this->actionLifecycle[] = 'beforeFormValidated')
            ->afterFormValidated(fn () => $this->actionLifecycle[] = 'afterFormValidated')
            ->before(fn () => $this->actionLifecycle[] = 'before')
            ->after(fn () => $this->actionLifecycle[] = 'after')
            ->mutateDataUsing(function (array $data): array {
                if ($this->actionMutate && isset($data['title'])) {
                    $data['title'] .= ' (mutated)';
                }

                return $data;
            })
            ->successNotificationTitle('Action saved')
            ->successRedirectUrl(fn (): ?string => $this->actionRedirect ? '/action-complete' : null);
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
