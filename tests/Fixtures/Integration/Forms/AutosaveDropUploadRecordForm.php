<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms;

class AutosaveDropUploadRecordForm extends AutosaveUploadRecordForm
{
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['settings']);

        return $data;
    }
}
