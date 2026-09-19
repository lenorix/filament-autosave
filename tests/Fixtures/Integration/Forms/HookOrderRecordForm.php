<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms;

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
