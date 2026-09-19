<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Illuminate\Database\Eloquent\Model;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Deep\DeepRelationshipEditPost;

/**
 * Mirrors lara-zeus/spatie-translatable 1.x: the concern refills the form
 * from getState(false) -- which does NOT save relationships -- and then
 * validates. The refill re-hydrates every relationship Repeater from the
 * database, discarding the user's pending rows and edits before the
 * package's own relationship pass has written them.
 */
class RehydratingHookEditPost extends DeepRelationshipEditPost
{
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $this->form->fill($this->form->getState(false));
        $this->form->validate();

        return parent::handleRecordUpdate($record, $data);
    }
}
