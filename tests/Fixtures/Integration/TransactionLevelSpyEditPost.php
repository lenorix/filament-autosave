<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class TransactionLevelSpyEditPost extends EditPost
{
    public array $levels = [];

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $this->levels[] = DB::transactionLevel();

        return parent::handleRecordUpdate($record, $data);
    }
}
