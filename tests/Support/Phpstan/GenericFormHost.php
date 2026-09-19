<?php

namespace Lenorix\FilamentAutosave\Tests\Support\Phpstan;

use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\HasAutosaveForForm;
use Livewire\Component;

/**
 * Analysis-only host for the generic-form trait, which also pulls in the
 * draft and upload traits. Never loaded at runtime.
 */
final class GenericFormHost extends Component implements HasSchemas
{
    use HasAutosaveForForm;
    use InteractsWithSchemas;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    protected function getAutosaveFormContext(): string
    {
        return 'phpstan';
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
