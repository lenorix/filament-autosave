<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\RichMerge;

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\RichUpload\PlainRichEditorEditPost;

/** HTML RichEditor column without an attachment provider, merged structurally. */
class RichMergeHtmlEditPost extends PlainRichEditorEditPost
{
    /** @return array<int, string> */
    protected function autosaveMergeFields(): ?array
    {
        return ['body'];
    }
}
