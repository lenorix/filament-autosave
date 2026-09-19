<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Browser;

use Filament\Forms\Components\RichEditor\RichContentCustomBlock;

/** A custom block with one config value, to prove blocks survive a merge whole. */
class BrowserCalloutBlock extends RichContentCustomBlock
{
    public static function getId(): string
    {
        return 'callout';
    }

    public static function getLabel(): string
    {
        return 'Callout';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function getPreviewLabel(array $config): string
    {
        return 'Callout: '.($config['text'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $data
     */
    public static function toHtml(array $config, array $data): ?string
    {
        return '<aside>'.e($config['text'] ?? '').'</aside>';
    }
}
