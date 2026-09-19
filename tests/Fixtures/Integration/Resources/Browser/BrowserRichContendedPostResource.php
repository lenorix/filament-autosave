<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Browser;

/** {@see BrowserRichMergePostResource} whose rich body can never be written. */
class BrowserRichContendedPostResource extends BrowserRichMergePostResource
{
    protected static ?string $slug = 'rich-contended-posts';

    public static function getPages(): array
    {
        return [
            'index' => BrowserRichContendedListPosts::route('/'),
            'edit' => BrowserRichContendedEditPost::route('/{record}/edit'),
        ];
    }
}
