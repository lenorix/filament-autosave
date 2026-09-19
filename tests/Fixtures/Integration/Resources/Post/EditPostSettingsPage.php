<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\HasAutosaveForForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models\Post;

/**
 * A custom resource page (not an EditRecord) with its own record-backed
 * form: a real `Filament\Resources\Pages\Page`, so Filament's
 * `RecordUpdated`/`RecordSaved` can be built for it.
 */
class EditPostSettingsPage extends Page
{
    use HasAutosaveForForm;

    protected static string $resource = PostResource::class;

    protected string $view = 'autosave-fixtures::generic-form-page';

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
            ->components([TextInput::make('title')])
            ->statePath('data');
    }

    public function getRecord(): Post
    {
        return $this->record;
    }

    protected function getAutosaveFormContext(): string
    {
        return 'settings:'.$this->record->getKey();
    }
}
