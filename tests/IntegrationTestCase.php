<?php

namespace Lenorix\FilamentAutosave\Tests;

use Filament\Facades\Filament;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Support\Facades\Schema;
use LaraZeus\SpatieTranslatable\SpatieTranslatableServiceProvider;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Panel\AutosavePanelProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;

/** Runs resource tests with in-memory SQLite. */
abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('posts', function ($table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->nullable();
            $table->text('settings')->nullable();
            $table->text('body')->nullable();
            $table->foreignId('category_id')->nullable();
            $table->string('featured_type')->nullable();
            $table->unsignedBigInteger('featured_id')->nullable();
        });

        Schema::create('categories', function ($table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('comments', function ($table) {
            $table->id();
            $table->string('commentable_type');
            $table->unsignedBigInteger('commentable_id');
            $table->text('body');
        });

        Schema::create('authors', function ($table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('author_post', function ($table) {
            $table->foreignId('author_id');
            $table->foreignId('post_id');
            $table->string('role')->nullable();
            $table->primary(['author_id', 'post_id']);
        });

        Schema::create('post_items', function ($table) {
            $table->id();
            $table->foreignId('post_id');
            $table->foreignId('category_id')->nullable();
            $table->string('label');
            $table->unsignedInteger('position')->nullable();
            $table->string('attachment')->nullable();
        });

        Schema::create('post_sub_items', function ($table) {
            $table->id();
            $table->foreignId('post_item_id');
            $table->string('label');
        });

        Schema::create('translatable_posts', function ($table) {
            $table->id();
            $table->json('title');
            $table->string('slug')->nullable();
        });

        Schema::create('post_sub_sub_items', function ($table) {
            $table->id();
            $table->foreignId('post_sub_item_id');
            $table->string('label');
        });

        Filament::setCurrentPanel('admin');
    }

    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            MediaLibraryServiceProvider::class,
            SpatieTranslatableServiceProvider::class,
            TablesServiceProvider::class,
            NotificationsServiceProvider::class,
            WidgetsServiceProvider::class,
            InfolistsServiceProvider::class,
            AutosavePanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }
}
