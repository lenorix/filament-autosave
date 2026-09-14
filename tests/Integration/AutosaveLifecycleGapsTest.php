<?php

use Filament\Forms\Components\TextInput;
use Filament\Forms\FormsComponent;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\HasAutosaveForForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\AutosaveUploadRecordForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;
use Livewire\Livewire;

class HookOrderRecordForm extends AutosaveUploadRecordForm
{
    public array $calls = [];

    protected function beforeSave(): void
    {
        $this->calls[] = 'beforeSave:'.($this->data['title'] ?? '');
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->calls[] = 'mutate';

        return $data;
    }
}

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

test('generic forms run beforeSave before the mutator, like Filament', function () {
    $post = Post::create(['title' => 'Post']);

    $page = Livewire::test(HookOrderRecordForm::class, ['record' => $post])
        ->set('data.title', 'Changed')
        ->call('autosave')->assertDispatched('autosave-status', status: 'saved');

    expect($page->get('calls'))->toBe(['beforeSave:Changed', 'mutate'])
        ->and($post->fresh()->title)->toBe('Changed');
});

test('a component with actions support falls back to its own form when no action is mounted', function () {
    Livewire::test(OwnFormActionsComponent::class)
        ->set('data.title', 'Draft')
        ->call('autosave')
        ->assertDispatched('autosave-status', status: 'saved')
        ->assertSet('autosaveHasDraft', true);
});

test('a blank required field is reported as pending even without a validation message', function () {
    $post = Post::create(['title' => 'Original', 'slug' => 'original']);

    Livewire::test(EditPost::class, ['record' => $post->getKey()])
        ->set('data.title', '')
        ->set('data.slug', 'changed')
        ->call('autosave')
        ->assertDispatched('autosave-status', fn (string $event, array $params): bool => in_array('title', $params['pending'] ?? [], true));
});
