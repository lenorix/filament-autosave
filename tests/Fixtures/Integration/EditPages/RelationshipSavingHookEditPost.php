<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Illuminate\Database\Eloquent\Model;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Deep\DeepRelationshipEditPost;

/**
 * Mirrors translatable Edit-page concerns (lara-zeus/spatie-translatable,
 * Filament's own): their handleRecordUpdate() calls $this->form->getState(),
 * which is Filament's full save path and persists relationships itself.
 */
class RelationshipSavingHookEditPost extends DeepRelationshipEditPost
{
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $this->form->fill($this->form->getState());

        return parent::handleRecordUpdate($record, $data);
    }
}
