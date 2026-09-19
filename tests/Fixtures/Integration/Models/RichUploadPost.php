<?php

namespace Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Models;

use Filament\Forms\Components\RichEditor\FileAttachmentProviders\SpatieMediaLibraryFileAttachmentProvider;
use Filament\Forms\Components\RichEditor\Models\Concerns\InteractsWithRichContent;
use Filament\Forms\Components\RichEditor\Models\Contracts\HasRichContent;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class RichUploadPost extends Post implements HasMedia, HasRichContent
{
    use InteractsWithMedia;
    use InteractsWithRichContent;

    protected $fillable = ['title', 'body'];

    protected $table = 'posts';

    protected $casts = ['body' => 'array'];

    protected function setUpRichContent(): void
    {
        $this->registerRichContent('body')
            ->fileAttachmentProvider(SpatieMediaLibraryFileAttachmentProvider::make()->collection('content'));
    }
}
