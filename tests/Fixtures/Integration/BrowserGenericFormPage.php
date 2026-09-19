<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration;

use Filament\Pages\Page;

/**
 * A plain panel page that hosts a generic `HasAutosaveForForm` component.
 *
 * The page itself uses no autosave trait, so the plugin injects nothing;
 * the component includes its own indicator.
 */
class BrowserGenericFormPage extends Page
{
    protected static ?string $slug = 'generic-form/{record}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'autosave-fixtures::generic-form-page';

    public Post $record;

    public function mount(Post $record): void
    {
        $this->record = $record;
    }
}
