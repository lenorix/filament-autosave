<?php

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
use Lenorix\FilamentAutosave\AutosaveManager;
use Lenorix\FilamentAutosave\Tests\Fixtures\AutosaveNestedCreateFormComponent;
use Lenorix\FilamentAutosave\Tests\Fixtures\AutosaveNestedEditFormComponent;

beforeEach(function () {
    Cache::flush();
});

test('edit autosave skips groups that hold a nested password', function () {
    $component = new AutosaveNestedEditFormComponent;
    $component->mountHasAutosave();
    $component->data = [
        'title' => 'Hello',
        'secrets' => ['label' => 'Prod', 'api_key' => 'plain-secret'],
    ];

    $component->autosave();

    expect($component->written)->not->toHaveKey('secrets');
    expect($component->written)->toHaveKey('title', 'Hello');
});

test('edit autosave skips password groups no matter the field order', function () {
    $component = new class extends AutosaveNestedEditFormComponent
    {
        public function form(Schema $schema): Schema
        {
            return $schema->components([
                TextInput::make('title'),
                Group::make([
                    TextInput::make('api_key')->password(),
                    TextInput::make('label'),
                ])->statePath('secrets'),
            ])->statePath('data');
        }
    };
    $component->mountHasAutosave();
    $component->data = ['title' => 'Hello', 'secrets' => ['api_key' => 's', 'label' => 'Prod']];

    $component->autosave();

    expect($component->written)->not->toHaveKey('secrets');
    expect($component->written)->toHaveKey('title', 'Hello');
});

test('edit autosave writes groups once every field qualifies', function () {
    $component = new AutosaveNestedEditFormComponent;
    $component->mountHasAutosave();
    $component->data = [
        'title' => 'Hello',
        'settings' => ['name' => 'John', 'mode' => 'slow'],
    ];

    $component->autosave();

    expect($component->written['settings'] ?? [])->toBe(['name' => 'John', 'mode' => 'slow']);
});

test('edit autosave skips groups with an invalid select option', function () {
    $component = new AutosaveNestedEditFormComponent;
    $component->mountHasAutosave();
    $component->data = [
        'title' => 'Hello',
        'settings' => ['name' => 'John', 'mode' => 'HACKED'],
    ];

    $component->autosave();

    expect($component->written)->not->toHaveKey('settings');
    expect($component->written)->toHaveKey('title', 'Hello');
});

test('edit autosave deletes undeclared keys inside nested groups', function () {
    $component = new AutosaveNestedEditFormComponent;
    $component->mountHasAutosave();
    $component->data = [
        'title' => 'Hello',
        'settings' => ['name' => 'John', 'mode' => 'fast', 'is_admin' => 1],
    ];

    $component->autosave();

    expect($component->written['settings'] ?? [])->toBe(['name' => 'John', 'mode' => 'fast']);
});

test('edit autosave drops repeaters that include password fields', function () {
    $component = new AutosaveNestedEditFormComponent;
    // Seed rows before Filament caches the schema.
    $component->data = ['vault' => ['row1' => ['label' => 'First', 'secret' => '']]];
    $component->mountHasAutosave();

    $component->data['title'] = 'Hello';
    $component->data['vault']['row1']['label'] = 'Edited';
    $component->data['vault']['row1']['secret'] = 'plain-row-secret';

    $component->autosave();

    expect($component->written)->not->toHaveKey('vault');
    expect($component->written)->toHaveKey('title', 'Hello');
});

test('edit autosave drops repeaters with an invalid select option', function () {
    $component = new AutosaveNestedEditFormComponent;
    $component->data = ['items' => ['row1' => ['label' => 'First', 'kind' => 'a']]];
    $component->mountHasAutosave();

    $component->data['title'] = 'Hello';
    $component->data['items']['row1']['kind'] = 'HACKED';

    $component->autosave();

    expect($component->written)->not->toHaveKey('items');
    expect($component->written)->toHaveKey('title', 'Hello');
});

test('edit autosave writes repeaters when all rows are valid', function () {
    $component = new AutosaveNestedEditFormComponent;
    $component->data = ['items' => ['row1' => ['label' => 'First', 'kind' => 'a']]];
    $component->mountHasAutosave();

    $component->data['title'] = 'Hello';
    $component->data['items']['row1']['label'] = 'Edited';

    $component->autosave();

    expect($component->written['items'] ?? [])->toBe([['label' => 'Edited', 'kind' => 'a']]);
});

test('create drafts strip nested passwords yet keep sibling fields', function () {
    $component = new AutosaveNestedCreateFormComponent;
    $component->mount();
    $component->data = [
        'title' => 'Hello',
        'settings' => ['name' => 'John', 'api_key' => 'plain-secret'],
    ];

    $component->autosave();

    $draft = Cache::get(AutosaveManager::cacheKey(AutosaveNestedCreateFormComponent::class));

    expect($draft['settings'] ?? [])->toBe(['name' => 'John']);
    expect($draft)->toHaveKey('title', 'Hello');
});
