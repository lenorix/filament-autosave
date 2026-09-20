<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Panel;

use Filament\Panel;
use Filament\PanelProvider;
use LaraZeus\SpatieTranslatable\SpatieTranslatablePlugin;
use Lenorix\FilamentAutosave\AutosavePlugin;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Browser\BrowserContendedPostResource;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Browser\BrowserMergePostResource;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Browser\BrowserRelationManagerPostResource;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Browser\BrowserReorderPostResource;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Browser\BrowserRichContendedPostResource;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Browser\BrowserRichJsonMergePostResource;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Browser\BrowserRichMergePostResource;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Browser\BrowserSilentPostResource;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Browser\BrowserUploadPostResource;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Builder\BuilderPostResource;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Deep\DeepRelationshipPostResource;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\MorphTo\MorphToPostResource;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\PollRelations\PollPostResource;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Polymorphic\PolymorphicPostResource;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Post\PostResource;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Relationship\NestedRelationshipPostResource;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Relationship\RelationshipPostResource;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\RichUpload\RichUploadPostResource;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Resources\Translatable\TranslatablePostResource;

class AutosavePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->resources([PostResource::class, RelationshipPostResource::class, PollPostResource::class, NestedRelationshipPostResource::class, DeepRelationshipPostResource::class, MorphToPostResource::class, PolymorphicPostResource::class, RichUploadPostResource::class, BuilderPostResource::class, TranslatablePostResource::class, BrowserUploadPostResource::class, BrowserReorderPostResource::class, BrowserMergePostResource::class, BrowserContendedPostResource::class, BrowserRichMergePostResource::class, BrowserRichJsonMergePostResource::class, BrowserRichContendedPostResource::class, BrowserSilentPostResource::class, BrowserRelationManagerPostResource::class])
            ->pages([BrowserGenericFormPage::class])
            ->plugins([
                AutosavePlugin::make(),
                SpatieTranslatablePlugin::make()->defaultLocales(['en', 'es']),
            ]);
    }
}
