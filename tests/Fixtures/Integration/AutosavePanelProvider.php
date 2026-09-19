<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Panel;
use Filament\PanelProvider;
use LaraZeus\SpatieTranslatable\SpatieTranslatablePlugin;
use Lenorix\FilamentAutosave\AutosavePlugin;

class AutosavePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->resources([PostResource::class, RelationshipPostResource::class, NestedRelationshipPostResource::class, DeepRelationshipPostResource::class, MorphToPostResource::class, PolymorphicPostResource::class, RichUploadPostResource::class, BuilderPostResource::class, TranslatablePostResource::class])
            ->plugins([
                AutosavePlugin::make(),
                SpatieTranslatablePlugin::make()->defaultLocales(['en', 'es']),
            ]);
    }
}
