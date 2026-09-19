<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms;

use Illuminate\Support\Str;

/**
 * A mutator that stores a transformed value: what the column holds is not
 * what the form shows, the common "slug from title" case.
 */
class SlugifyingRecordForm extends AutosaveColumnsRecordForm
{
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (array_key_exists('slug', $data)) {
            $data['slug'] = Str::slug((string) $data['slug']);
        }

        return $data;
    }
}
