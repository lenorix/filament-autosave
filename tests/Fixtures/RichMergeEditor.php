<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures;

use Filament\Forms\Components\RichEditor\TipTapExtensions\CustomBlockExtension;
use Filament\Forms\Components\RichEditor\TipTapExtensions\DetailsContentExtension;
use Filament\Forms\Components\RichEditor\TipTapExtensions\DetailsExtension;
use Filament\Forms\Components\RichEditor\TipTapExtensions\DetailsSummaryExtension;
use Filament\Forms\Components\RichEditor\TipTapExtensions\GridColumnExtension;
use Filament\Forms\Components\RichEditor\TipTapExtensions\GridExtension;
use Filament\Forms\Components\RichEditor\TipTapExtensions\ImageExtension;
use Filament\Forms\Components\RichEditor\TipTapExtensions\MentionExtension;
use Filament\Forms\Components\RichEditor\TipTapExtensions\MergeTagExtension;
use Filament\Forms\Components\RichEditor\TipTapExtensions\TextColorExtension;
use Tiptap\Editor;
use Tiptap\Extensions\StarterKit;
use Tiptap\Marks\Link;

/**
 * A Tiptap PHP editor with the same node set Filament's RichEditor
 * registers, so engine tests see real Filament documents.
 */
final class RichMergeEditor
{
    public static function make(): Editor
    {
        return new Editor([
            'extensions' => [
                new StarterKit,
                new Link,
                new ImageExtension,
                new CustomBlockExtension,
                new MentionExtension,
                new MergeTagExtension,
                new DetailsExtension,
                new DetailsSummaryExtension,
                new DetailsContentExtension,
                new GridExtension,
                new GridColumnExtension,
                new TextColorExtension,
            ],
        ]);
    }
}
