<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class FailingAutosaveEditPost extends EditPost
{
    protected ?bool $hasDatabaseTransactions = true;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        parent::handleRecordUpdate($record, $data);

        throw new RuntimeException('Failure after writing the record');
    }
}
