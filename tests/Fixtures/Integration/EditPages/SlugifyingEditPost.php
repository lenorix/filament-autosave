<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Illuminate\Support\Str;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\EditPost;

/**
 * A mutator that stores a transformed value: what the column holds is not
 * what the form shows, the common "slug from title" case.
 */
class SlugifyingEditPost extends EditPost
{
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (array_key_exists('slug', $data)) {
            $data['slug'] = Str::slug((string) $data['slug']);
        }

        return $data;
    }
}
