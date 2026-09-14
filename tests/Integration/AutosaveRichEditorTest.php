<?php

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\AutosaveUploadRecordForm;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\EditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Post;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\PostResource;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\RichUploadEditPost;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\RichUploadPost;
use Livewire\Livewire;

beforeEach(function () {
    $migration = require __DIR__.'/../../vendor/spatie/laravel-medialibrary/database/migrations/create_media_table.php.stub';
    $migration->up();
});

test('rich editor attachment cleanup runs during autosave and disables undo', function () {
    $post = RichUploadPost::create([
        'title' => 'Post',
        'body' => ['type' => 'doc', 'content' => []],
    ]);
    $media = $post->addMediaFromString('image')->usingFileName('image.png')->toMediaCollection('content', 'public');
    $post->update(['body' => [
        'type' => 'doc',
        'content' => [['type' => 'image', 'attrs' => ['id' => $media->uuid, 'src' => $media->getUrl(), 'alt' => null]]],
    ]]);

    $page = Livewire::test(RichUploadEditPost::class, ['record' => $post->getKey()]);
    $page->set('data.body', ['type' => 'doc', 'content' => []])->call('autosave');

    expect($post->fresh()->getMedia('content'))->toHaveCount(0)
        ->and($page->get('autosaveCanUndo'))->toBeFalse();
});

class PlainRichPost extends Post
{
    protected $table = 'posts';

    protected $fillable = ['title', 'body'];
}

class PlainRichEditorPostResource extends PostResource
{
    protected static ?string $model = PlainRichPost::class;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')->required(),
            RichEditor::make('body'),
        ]);
    }
}

class PlainRichEditorEditPost extends EditPost
{
    protected static string $resource = PlainRichEditorPostResource::class;
}

test('a plain rich editor without attachments autosaves its content as a column', function () {
    $post = PlainRichPost::create(['title' => 'Post', 'body' => '<p>old</p>']);

    $page = Livewire::test(PlainRichEditorEditPost::class, ['record' => $post->getKey()])
        ->set('data.body', '<p>new text</p>')
        ->call('autosave')->assertDispatched('autosave-status', status: 'saved');

    expect($post->fresh()->body)->toContain('new text');

    $page->call('undoAutosave');
    expect($post->fresh()->body)->toContain('old');
});

test('a rich editor with an attachment provider keeps its content while cleaning attachments', function () {
    $post = RichUploadPost::create(['title' => 'Post', 'body' => ['type' => 'doc', 'content' => []]]);
    $media = $post->addMediaFromString('image')->usingFileName('image.png')->toMediaCollection('content', 'public');
    $post->update(['body' => [
        'type' => 'doc',
        'content' => [['type' => 'image', 'attrs' => ['id' => $media->uuid, 'src' => $media->getUrl(), 'alt' => null]]],
    ]]);
    $body = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'kept']]]]];

    Livewire::test(RichUploadEditPost::class, ['record' => $post->getKey()])
        ->set('data.body', $body)->call('autosave')->assertDispatched('autosave-status', status: 'saved');

    expect($post->fresh()->body)->toBe($body)
        ->and($post->fresh()->getMedia('content'))->toHaveCount(0);
});

class PlainRichEditorRecordForm extends AutosaveUploadRecordForm
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->model($this->record)
            ->components([
                TextInput::make('title'),
                RichEditor::make('body'),
            ])
            ->statePath('data');
    }
}

test('a generic record form autosaves plain rich editor content', function () {
    $post = PlainRichPost::create(['title' => 'Post', 'body' => '<p>old</p>']);

    Livewire::test(PlainRichEditorRecordForm::class, ['record' => $post])
        ->set('data.body', '<p>new text</p>')
        ->call('autosave')->assertDispatched('autosave-status', status: 'saved');

    expect($post->fresh()->body)->toContain('new text');
});
