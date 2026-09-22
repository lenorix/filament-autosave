<?php

namespace Lenorix\FilamentAutosave\Tests;

use Filament\Facades\Filament;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use LaraZeus\SpatieTranslatable\SpatieTranslatableServiceProvider;
use Lenorix\FilamentAutosave\Tests\Fixtures\Integration\Panel\AutosavePanelProvider;
use Spatie\MediaLibrary\MediaLibraryServiceProvider;

/**
 * Runs resource tests against an in-memory SQLite database by default.
 * Set INTEGRATION_TEST_DB_CONNECTION (pgsql, mysql, mariadb, sqlite) plus
 * INTEGRATION_TEST_DB_HOST/PORT/DATABASE/USERNAME/PASSWORD to run the same
 * suite against a real server, e.g. in CI. Every table this suite (and any
 * package migration, such as spatie/laravel-medialibrary's `media` table)
 * creates is dropped first, since a real connection is reused across every
 * test method instead of getting a fresh `:memory:` database each time.
 */
abstract class IntegrationTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        View::addNamespace('autosave-fixtures', __DIR__.'/Fixtures/views');

        Schema::dropAllTables();

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

        Schema::create('poll_posts', function ($table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('attachment')->nullable();
            $table->timestamps();
        });

        Schema::create('poll_items', function ($table) {
            $table->id();
            $table->foreignId('poll_post_id');
            $table->string('label');
            $table->unsignedInteger('position')->nullable();
            $table->timestamps();
        });

        Schema::create('poll_notes', function ($table) {
            $table->id();
            $table->foreignId('poll_post_id');
            $table->text('body')->nullable();
        });

        Schema::create('uuid_poll_posts', function ($table) {
            $table->string('id')->primary();
            $table->string('title')->nullable();
            $table->timestamps();
        });

        Schema::create('uuid_poll_items', function ($table) {
            $table->string('id')->primary();
            $table->string('uuid_poll_post_id');
            $table->string('label');
            $table->timestamps();
        });

        Schema::create('author_poll_post', function ($table) {
            $table->foreignId('author_id');
            $table->foreignId('poll_post_id');
            $table->string('role')->nullable();
            $table->timestamps();
            $table->primary(['author_id', 'poll_post_id']);
        });

        // Self-referential: children() points back at the same table, so a
        // schema that nests the same relationship component inside itself
        // renders a cyclic relation *type* graph, not just a deep one.
        Schema::create('cycle_nodes', function ($table) {
            $table->id();
            $table->foreignId('parent_id')->nullable();
            $table->string('label');
            $table->timestamps();
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

        $driver = env('INTEGRATION_TEST_DB_CONNECTION', 'sqlite');

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $driver === 'sqlite' ? [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ] : [
            'driver' => $driver,
            'host' => env('INTEGRATION_TEST_DB_HOST', '127.0.0.1'),
            'port' => env('INTEGRATION_TEST_DB_PORT', $driver === 'pgsql' ? '5432' : '3306'),
            'database' => env('INTEGRATION_TEST_DB_DATABASE', 'filament_autosave_test'),
            'username' => env('INTEGRATION_TEST_DB_USERNAME', $driver === 'pgsql' ? 'postgres' : 'root'),
            'password' => env('INTEGRATION_TEST_DB_PASSWORD', ''),
            'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
            'collation' => $driver === 'pgsql' ? null : 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]);
    }
}
