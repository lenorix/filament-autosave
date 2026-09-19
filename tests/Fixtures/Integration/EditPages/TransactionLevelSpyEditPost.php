<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPages;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\EditPost;

class TransactionLevelSpyEditPost extends EditPost
{
    public array $levels = [];

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $this->levels[] = DB::transactionLevel();

        return parent::handleRecordUpdate($record, $data);
    }
}
