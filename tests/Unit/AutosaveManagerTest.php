<?php

use Filament\Facades\Filament;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Lenorix\FilamentAutosave\AutosaveManager;

test('field filtering cuts out the named field', function () {
    $data = ['name' => 'John', 'password' => 'secret', 'email' => 'john@example.com'];

    $filtered = AutosaveManager::excludeFields($data, ['password']);

    expect($filtered)->toBe(['name' => 'John', 'email' => 'john@example.com']);
});

test('field filtering cuts out several excluded fields at once', function () {
    $data = ['name' => 'John', 'password' => 'secret', 'password_confirmation' => 'secret', 'email' => 'john@example.com'];

    $filtered = AutosaveManager::excludeFields($data, ['password', 'password_confirmation']);

    expect($filtered)->toBe(['name' => 'John', 'email' => 'john@example.com']);
});

test('an empty exclusion list keeps every field', function () {
    $data = ['name' => 'John', 'email' => 'john@example.com'];

    expect(AutosaveManager::excludeFields($data, []))->toBe($data);
});

test('the same data hashes to the same snapshot value', function () {
    $data = ['name' => 'John', 'email' => 'john@example.com'];

    expect(AutosaveManager::snapshotHash($data))
        ->toBe(AutosaveManager::snapshotHash($data));
});

test('altered field values produce a different snapshot hash', function () {
    expect(AutosaveManager::snapshotHash(['name' => 'John']))
        ->not->toBe(AutosaveManager::snapshotHash(['name' => 'Jane']));
});

test('snapshot hashes pay no attention to top-level key order', function () {
    $a = ['name' => 'John', 'email' => 'john@example.com'];
    $b = ['email' => 'john@example.com', 'name' => 'John'];

    expect(AutosaveManager::snapshotHash($a))->toBe(AutosaveManager::snapshotHash($b));
});

test('reordering repeater rows changes the snapshot hash', function () {
    // Keep row values unchanged while swapping their UUID keys.
    $before = ['items' => ['b-uuid' => ['title' => 'B'], 'a-uuid' => ['title' => 'A']]];
    $after = ['items' => ['a-uuid' => ['title' => 'A'], 'b-uuid' => ['title' => 'B']]];

    expect(AutosaveManager::snapshotHash($before))
        ->not->toBe(AutosaveManager::snapshotHash($after));
});

test('snapshot hashes tell values with backslashes apart', function () {
    expect(AutosaveManager::snapshotHash(['value' => 'a\\b']))
        ->not->toBe(AutosaveManager::snapshotHash(['value' => 'ab']));
});

test('snapshot hashes separate distinct malformed UTF-8 values', function () {
    $a = AutosaveManager::snapshotHash(['value' => "\xB1\x31"]);
    $b = AutosaveManager::snapshotHash(['value' => "\xC3\x28"]);

    expect($a)->not->toBe($b);
});

test('snapshot hashes split valid from invalid UTF-8 values', function () {
    expect(AutosaveManager::snapshotHash(['value' => 'ok']))
        ->not->toBe(AutosaveManager::snapshotHash(['value' => "\xB1\x31"]));
});

test('cache keys for logged-in users contain the guard, user ID, and page class', function () {
    fakeFilamentPanel(guard: 'web', id: 9);

    expect(AutosaveManager::cacheKey('App\\Some\\Page'))
        ->toBe('filament-autosave:web:9:App\\Some\\Page');
});

test('guest cache keys hide the raw session ID', function () {
    auth()->logout();
    $sessionId = session()->getId();

    expect(AutosaveManager::cacheKey('App\\Some\\Page'))->not->toContain($sessionId);
});

test('cache keys carry the active tenant', function () {
    $tenant = new class extends Model
    {
        protected $guarded = [];
    };
    $tenant->setAttribute($tenant->getKeyName(), 7);

    Filament::shouldReceive('getCurrentPanel')->andReturnNull();
    Filament::shouldReceive('getTenant')->andReturn($tenant);

    expect(AutosaveManager::cacheKey('App\\Some\\Page'))->toContain(':7:');
});

test('the cache scope draws on the panel guard and user ID', function () {
    fakeFilamentPanel(guard: 'admins', id: 42);

    expect(AutosaveManager::currentScope())->toBe('admins:42');
});

test('the cache scope separates guards that share a user ID', function (string $guard) {
    fakeFilamentPanel(guard: $guard, id: 1);

    expect(AutosaveManager::currentScope())->toBe($guard.':1');
})->with(['admins', 'customers']);

test('guest cache keys still get a fallback scope', function () {
    auth()->logout();

    $key = AutosaveManager::cacheKey('App\\Some\\Page');

    expect($key)->toContain('filament-autosave:');
    expect($key)->not->toBe('filament-autosave::App\\Some\\Page');
});

test('a stored draft comes back with its full data', function () {
    $key = 'filament-autosave:test:draft';
    $data = ['title' => 'My Article', 'body' => 'Content here'];

    AutosaveManager::storeDraft($key, $data, 1);

    expect(AutosaveManager::restoreDraft($key))->toBe($data);
});

test('clearing a draft erases it from the cache', function () {
    $key = 'filament-autosave:test:clear';
    AutosaveManager::storeDraft($key, ['title' => 'Test'], 1);

    AutosaveManager::clearDraft($key);

    expect(AutosaveManager::restoreDraft($key))->toBeNull();
});

test('asking for a draft that never existed returns null', function () {
    expect(AutosaveManager::restoreDraft('filament-autosave:nonexistent:key'))->toBeNull();
});

test("restoring a draft refuses cached values that aren't arrays", function () {
    $key = 'filament-autosave:test:string';
    Cache::put($key, 'not-an-array', 3600);

    expect(AutosaveManager::restoreDraft($key))->toBeNull();
});

test('draft storage keeps users and tenants apart when restoring and clearing', function () {
    $tenant = new class extends Model {};
    $tenant->setAttribute('id', 10);
    Filament::shouldReceive('getTenant')->andReturnUsing(fn () => $tenant);
    Filament::shouldReceive('getCurrentPanel')->andReturnNull();
    $user = new GenericUser(['id' => 1]);
    auth()->setUser($user);

    $ownerKey = AutosaveManager::cacheKey('CreatePost');
    AutosaveManager::storeDraft($ownerKey, ['title' => 'Private'], 1);

    $user->id = 2;
    $otherUserKey = AutosaveManager::cacheKey('CreatePost');
    expect(AutosaveManager::restoreDraft($otherUserKey))->toBeNull();
    AutosaveManager::clearDraft($otherUserKey);

    $user->id = 1;
    $tenant->setAttribute('id', 20);
    $otherTenantKey = AutosaveManager::cacheKey('CreatePost');
    expect(AutosaveManager::restoreDraft($otherTenantKey))->toBeNull();
    AutosaveManager::clearDraft($otherTenantKey);

    $tenant->setAttribute('id', 10);
    expect(AutosaveManager::restoreDraft(AutosaveManager::cacheKey('CreatePost')))
        ->toBe(['title' => 'Private']);
});

test("one guest session can't recover another's draft", function () {
    auth()->logout();
    session()->setId(str_repeat('a', 40));
    $key = AutosaveManager::cacheKey('CreatePost');
    AutosaveManager::storeDraft($key, ['title' => 'Private'], 1);

    session()->setId(str_repeat('b', 40));
    expect(AutosaveManager::restoreDraft(AutosaveManager::cacheKey('CreatePost')))->toBeNull();

    session()->setId(str_repeat('a', 40));
    expect(AutosaveManager::restoreDraft(AutosaveManager::cacheKey('CreatePost')))
        ->toBe(['title' => 'Private']);
});

test('drafts lapse after the configured number of hours', function () {
    $this->freezeTime();
    AutosaveManager::storeDraft('expiring-draft', ['title' => 'Draft'], 2);

    $this->travel(119)->minutes();
    expect(AutosaveManager::restoreDraft('expiring-draft'))->toBe(['title' => 'Draft']);

    $this->travel(2)->minutes();
    expect(AutosaveManager::restoreDraft('expiring-draft'))->toBeNull();
});
