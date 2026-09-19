<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Browser;

use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\PostResource;

/** The plain Post resource served by a page whose autosave never answers. */
class BrowserSilentPostResource extends PostResource
{
    protected static ?string $slug = 'silent-posts';

    public static function getPages(): array
    {
        return [
            'index' => BrowserSilentListPosts::route('/'),
            'edit' => BrowserSilentEditPost::route('/{record}/edit'),
        ];
    }
}
