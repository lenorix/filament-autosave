<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures;

use Lenorix\FilamentAutosave\HasAutosaveForCreate;

/** Create-page double that stores drafts in cache. */
class FakeCreatePage
{
    use HasAutosaveForCreate;

    public object $form;

    public ?array $data = [];

    /** @var array<int, array<string, mixed>> */
    public array $dispatched = [];

    /** @var array<string> */
    public array $exceptFields = [];

    /** @param  array<string, mixed>  $formState */
    public function __construct(array $formState = [])
    {
        $this->form = new FakeFormState($formState);
    }

    public function dispatch(string $event, ...$params): void
    {
        $this->dispatched[] = ['event' => $event, 'params' => $params];
    }

    public function authorizeAccess(): void {}

    /** @return array<string> */
    protected function autosaveExcept(): array
    {
        return $this->exceptFields;
    }
}
