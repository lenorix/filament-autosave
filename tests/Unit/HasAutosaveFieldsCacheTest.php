<?php

use Lenorix\FilamentAutosave\HasAutosaveBase;
use Lenorix\FilamentAutosave\Tests\Fixtures\AutosaveNestedEditFormComponent;

function fieldTreePage(): object
{
    return new class
    {
        use HasAutosaveBase;

        public object $form;

        public function __construct()
        {
            $this->form = new class
            {
                public int $calls = 0;

                public function getRawState(): array
                {
                    return ['title' => 'X'];
                }

                public function fill(array $data): void {}

                public function getFlatFields(bool $withHidden = false): array
                {
                    $this->calls++;

                    return ['title' => new stdClass, 'settings.name' => new stdClass];
                }
            };
        }
    };
}

test('listing autosave fields reaches for the flat field list only once', function () {
    $page = fieldTreePage();

    (fn () => $this->getAutosaveFields())->call($page);
    (fn () => $this->getAutosaveFields())->call($page);

    expect($page->form->calls)->toBe(1);
});

test('repeated field listings return an identical map on first and second call', function () {
    $page = fieldTreePage();

    $first = (fn () => $this->getAutosaveFields())->call($page);
    $second = (fn () => $this->getAutosaveFields())->call($page);

    expect($first)->toEqual($second);
});

test('a real form reports the same field paths on every call', function () {
    $component = new AutosaveNestedEditFormComponent;
    $component->data = ['items' => ['row1' => ['label' => 'a', 'kind' => 'b']]];
    $component->mountHasAutosave();

    $first = (fn () => $this->getAutosaveFields())->call($component);
    $second = (fn () => $this->getAutosaveFields())->call($component);

    expect(array_keys($first))->toEqual(array_keys($second));
    expect($first)->toHaveKey('items.*.label');
    expect($first)->toHaveKey('settings.name');
});
