<?php

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
use Lenorix\FilamentAutosave\AutosaveManager;
use Lenorix\FilamentAutosave\Tests\Fixtures\AutosavePasswordCreateFormComponent;
use Lenorix\FilamentAutosave\Tests\Fixtures\AutosavePasswordEditFormComponent;

beforeEach(function () {
    Cache::flush();
});

test('edit autosave keeps password fields out of record writes', function () {
    $component = new AutosavePasswordEditFormComponent;
    $component->form->fill();
    $component->mountHasAutosave();
    $component->data = ['title' => 'Hello', 'vault_key' => 'half-typed-secr'];

    $component->autosave();

    expect($component->written)
        ->toBe(['title' => 'Hello']);
});

test('create autosave keeps password fields out of drafts', function () {
    $component = new AutosavePasswordCreateFormComponent;
    $component->form->fill();
    $component->mountHasAutosaveForCreate();
    $component->data = ['title' => 'Hello', 'vault_key' => 'super-secret'];

    $component->autosave();

    $draft = AutosaveManager::restoreDraft(
        AutosaveManager::cacheKey(AutosavePasswordCreateFormComponent::class)
    );

    expect($draft)->toBe(['title' => 'Hello']);
});

test('a change touching only passwords never reaches the record', function () {
    $component = new AutosavePasswordEditFormComponent;
    $component->form->fill(['title' => 'Hello']);
    $component->mountHasAutosave();
    $component->data = ['title' => 'Hello', 'vault_key' => 'typing'];

    $component->autosave();

    expect($component->written)->toBe([]);
});

test('password input types are dropped even without password()', function () {
    $component = new class extends AutosavePasswordEditFormComponent
    {
        public function form(Schema $schema): Schema
        {
            return $schema->components([
                TextInput::make('title'),
                TextInput::make('vault_key')->type('password'),
            ])->statePath('data');
        }
    };
    $component->mountHasAutosave();
    $component->data = ['title' => 'Hello', 'vault_key' => 'typed-secret'];

    $component->autosave();

    expect($component->written)->not->toHaveKey('vault_key');
    expect($component->written)->toHaveKey('title', 'Hello');
});

test('the default exclusions recognize familiar credential field names', function () {
    expect(config('filament-autosave.except'))
        ->toContain('password')
        ->toContain('password_confirmation')
        ->toContain('current_password');
});
