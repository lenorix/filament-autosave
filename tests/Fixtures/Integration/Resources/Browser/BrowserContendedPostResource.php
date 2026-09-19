<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Browser;

/** The merge resource served by a page whose conditional writes never land. */
class BrowserContendedPostResource extends BrowserMergePostResource
{
    protected static ?string $slug = 'contended-posts';

    public static function getPages(): array
    {
        return [
            'index' => BrowserContendedListPosts::route('/'),
            'edit' => BrowserContendedEditPost::route('/{record}/edit'),
        ];
    }
}
