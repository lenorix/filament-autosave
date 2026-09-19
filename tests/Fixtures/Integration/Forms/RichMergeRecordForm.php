<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Forms;

/** Record-backed generic form with an HTML RichEditor listed as mergeable. */
class RichMergeRecordForm extends PlainRichEditorRecordForm
{
    /** @return array<int, string> */
    protected function autosaveMergeFields(): ?array
    {
        return ['body'];
    }

    protected function getAutosaveFormContext(): string
    {
        return 'rich-merge:'.$this->record->getKey();
    }
}
