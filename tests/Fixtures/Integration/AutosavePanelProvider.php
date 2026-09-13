<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Panel;
use Filament\PanelProvider;
use Lenorix\FilamentAutosave\AutosavePlugin;

class AutosavePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->resources([PostResource::class, RelationshipPostResource::class, NestedRelationshipPostResource::class, DeepRelationshipPostResource::class, MorphToPostResource::class, PolymorphicPostResource::class, RichUploadPostResource::class, BuilderPostResource::class])
            ->plugins([AutosavePlugin::make()]);
    }
}
