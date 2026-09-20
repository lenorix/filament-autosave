<?php

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
use Lenorix\FilamentAutosave\AutosaveManager;
use Lenorix\FilamentAutosave\Tests\Fixtures\AutosaveCheckboxListEditFormComponent;
use Lenorix\FilamentAutosave\Tests\Fixtures\AutosaveCreateFormComponent;
use Lenorix\FilamentAutosave\Tests\Fixtures\AutosaveEditFormComponent;
use Lenorix\FilamentAutosave\Tests\Fixtures\AutosaveMultiOptionCreateFormComponent;
use Lenorix\FilamentAutosave\Tests\Fixtures\AutosaveSelectEditFormComponent;

// Test dehydration directly, without rendering Livewire.
beforeEach(function () {
    Cache::flush();
});

/**
 * Some older Filament 4 releases' Select::getOptionLabels() iterated a null state without
 * wrapping it (fixed upstream), so a multiple Select whose key is absent from
 * the state crashes inside Filament, not in the package. Probe the behaviour
 * instead of a version number.
 */
function filamentSelectRejectsNullMultipleState(): bool
{
    // Read Filament's own guard rather than a version number: releases after
    // newer releases wrap the state (`$state ?? []`) before iterating it.
    $method = new ReflectionMethod(Select::class, 'getOptionLabels');
    $lines = array_slice(file($method->getFileName()), $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);
    $source = implode('', $lines);

    return ! str_contains($source, '?? []') && ! str_contains($source, 'Arr::wrap');
}

test('edit autosave writes the forms dehydrated values', function () {
    $component = new AutosaveEditFormComponent;
    $component->mountHasAutosave();
    $component->data = ['title' => 'Hello', 'code' => 'abc'];

    $component->autosave();

    expect($component->written)->toBe(['title' => 'Hello', 'code' => 'ABC']);
});

test('edit autosave runs before-state-dehydrated callbacks', function () {
    $component = new class extends AutosaveEditFormComponent
    {
        public bool $beforeDehydrated = false;

        public function form(Schema $schema): Schema
        {
            return $schema
                ->components([
                    TextInput::make('title')->beforeStateDehydrated(function (): void {
                        $this->beforeDehydrated = true;
                    }),
                ])
                ->statePath('data');
        }
    };

    $component->mount();
    $component->data = ['title' => 'Hello'];
    $component->autosave();

    expect($component->beforeDehydrated)->toBeTrue();
});

test('edit autosave skips fields whose dehydration is switched off', function () {
    $component = new AutosaveEditFormComponent;
    $component->mountHasAutosave();
    $component->data = ['title' => 'Hello', 'secret' => 'top-secret'];

    $component->autosave();

    expect($component->written)->not->toHaveKey('secret');
    expect($component->written)->toHaveKey('title', 'Hello');
});

test('edit autosave drops blank required fields', function () {
    $component = new AutosaveEditFormComponent;
    $component->mountHasAutosave();

    $component->data = ['title' => 'Hello', 'slug' => ''];

    $component->autosave();

    expect($component->written)->not->toHaveKey('slug');
    expect($component->written)->toHaveKey('title', 'Hello');
});

test('edit autosave applies the form field validation rules', function () {
    $component = new class extends AutosaveEditFormComponent
    {
        public function form(Schema $schema): Schema
        {
            return $schema
                ->components([
                    TextInput::make('short_name')->minLength(3),
                    TextInput::make('age')->numeric()->maxValue(10),
                    TextInput::make('title'),
                ])
                ->statePath('data');
        }
    };

    $component->mount();
    $component->data = [
        'short_name' => 'no',
        'age' => '11',
        'title' => 'kept',
    ];

    $component->autosave();

    expect($component->written)->toBe(['title' => 'kept']);
});

test("edit autosave refuses select options that aren't in the list", function () {
    $component = new AutosaveSelectEditFormComponent;
    $component->mountHasAutosave();

    $component->data = ['title' => 'Hello', 'role' => 'superadmin'];

    $component->autosave();

    expect($component->written)->not->toHaveKey('role');
    expect($component->written)->toHaveKey('title', 'Hello');
});

test('edit autosave keeps select options that are permitted', function () {
    $component = new AutosaveSelectEditFormComponent;
    $component->mountHasAutosave();

    $component->data = ['title' => 'Hello', 'role' => 'editor'];

    $component->autosave();

    expect($component->written)->toHaveKey('role', 'editor');
});

test('edit autosave stores valid CheckboxList picks', function () {
    $component = new AutosaveCheckboxListEditFormComponent;
    $component->mountHasAutosave();

    $component->data = ['title' => 'Hello', 'tags' => ['a', 'c']];

    $component->autosave();

    expect($component->written)->toHaveKey('tags', ['a', 'c']);
    expect($component->written)->toHaveKey('title', 'Hello');
});

test('edit autosave drops a CheckboxList holding an invalid option', function () {
    $component = new AutosaveCheckboxListEditFormComponent;
    $component->mountHasAutosave();

    $component->data = ['title' => 'Hello', 'tags' => ['a', 'zzz']];

    $component->autosave();

    expect($component->written)->not->toHaveKey('tags');
    expect($component->written)->toHaveKey('title', 'Hello');
});

test('edit autosave strips undeclared fields from client payloads', function () {
    $component = new AutosaveEditFormComponent;
    $component->mountHasAutosave();

    $component->data = ['title' => 'Hello', 'is_admin' => 1];

    $component->autosave();

    expect($component->written)->not->toHaveKey('is_admin');
    expect($component->written)->toHaveKey('title', 'Hello');
});

test('create autosave stores raw input verbatim in drafts', function () {
    $component = new AutosaveCreateFormComponent;
    $component->mountHasAutosaveForCreate();
    $component->data = ['title' => 'Hello', 'code' => 'abc'];

    $component->autosave();

    $draft = Cache::get(AutosaveManager::cacheKey(AutosaveCreateFormComponent::class));

    expect($draft)->toBe(['title' => 'Hello', 'code' => 'abc']);
});

test('create autosave keeps valid multi-option arrays in drafts', function () {
    $component = new AutosaveMultiOptionCreateFormComponent;
    $component->mount();

    $component->data = [
        'title' => 'Hello',
        'tags' => ['a', 'c'],
        'perms' => ['r', 'w'],
        'roles' => ['admin', 'editor'],
    ];

    $component->autosave();

    $draft = Cache::get(AutosaveManager::cacheKey(AutosaveMultiOptionCreateFormComponent::class));

    expect($draft)->toBe([
        'title' => 'Hello',
        'tags' => ['a', 'c'],
        'perms' => ['r', 'w'],
        'roles' => ['admin', 'editor'],
    ]);
});

test('create autosave still saves drafts whose option fields are empty', function () {
    $component = new AutosaveMultiOptionCreateFormComponent;
    $component->mount();

    // Empty option fields cover Filament versions with rule-building issues.
    $component->data = ['title' => 'Hello'];

    $component->autosave();

    $draft = Cache::get(AutosaveManager::cacheKey(AutosaveMultiOptionCreateFormComponent::class));

    expect($draft)->toBe(['title' => 'Hello']);
})->skip(fn (): bool => filamentSelectRejectsNullMultipleState(), 'This Filament version cannot label a null multiple Select state');

test('create autosave skips an option array that holds an invalid entry', function () {
    $component = new AutosaveMultiOptionCreateFormComponent;
    $component->mount();

    $component->data = [
        'title' => 'Hello',
        'tags' => ['a', 'nope'],
    ];

    $component->autosave();

    $draft = Cache::get(AutosaveManager::cacheKey(AutosaveMultiOptionCreateFormComponent::class));

    expect($draft)->toHaveKey('title', 'Hello');
    expect($draft)->not->toHaveKey('tags');
})->skip(fn (): bool => filamentSelectRejectsNullMultipleState(), 'This Filament version cannot label a null multiple Select state');
