<?php

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Lenorix\FilamentAutosave\AutosaveUploadLedger;

beforeEach(function () {
    Cache::flush();
    Storage::fake('public');
    config(['filament-autosave.upload_ledger_ttl' => 180]);
});

test('the upload ledger removes files when a transaction rolls back', function () {
    Storage::disk('public')->put('staged.txt', 'staged');
    $ledger = app(AutosaveUploadLedger::class);
    $token = $ledger->register([['disk' => 'public', 'path' => 'staged.txt']]);

    $ledger->rollback($token);

    Storage::disk('public')->assertMissing('staged.txt');
    expect(Cache::get('filament-autosave:upload-ledger'))->toBeNull();
});

test('committed uploads are removed from the ledger and pruning removes stale entries', function () {
    Storage::disk('public')->put('committed.txt', 'committed');
    Storage::disk('public')->put('stale.txt', 'stale');
    $ledger = app(AutosaveUploadLedger::class);
    $committed = $ledger->register([['disk' => 'public', 'path' => 'committed.txt']]);
    $stale = $ledger->register([['disk' => 'public', 'path' => 'stale.txt']], -1);

    $ledger->commit($committed);
    expect($ledger->prune())->toBe(1);

    Storage::disk('public')->assertExists('committed.txt');
    Storage::disk('public')->assertMissing('stale.txt');
    expect($stale)->toBeString();
});

test('a ledger token remains available until the database commit callback runs', function () {
    Storage::disk('public')->put('pending.txt', 'pending');
    $ledger = app(AutosaveUploadLedger::class);
    $token = $ledger->register([['disk' => 'public', 'path' => 'pending.txt']]);

    DB::beginTransaction();
    DB::afterCommit(fn () => $ledger->commit($token));

    expect(Cache::get('filament-autosave:upload-ledger'))->toHaveKey($token);

    DB::commit();

    expect(Cache::get('filament-autosave:upload-ledger'))->toBeNull();
    Storage::disk('public')->assertExists('pending.txt');
});

test('concurrent registrations do not overwrite each other under the ledger lock', function () {
    $ledger = app(AutosaveUploadLedger::class);

    // Hold the index lock as a stand-in for a concurrent request already
    // mid read-modify-write, then let a second registration queue behind it
    // instead of racing the same stale copy of the index.
    $lock = Cache::lock('filament-autosave:upload-ledger:lock', 5);
    expect($lock->get())->toBeTrue();

    $first = $ledger->register([['disk' => 'public', 'path' => 'first.txt']]);
    Cache::put('filament-autosave:upload-ledger', [
        $first => ['files' => [['disk' => 'public', 'path' => 'first.txt']], 'expires_at' => now()->addMinutes(180)->timestamp],
    ]);

    $lock->release();

    $second = $ledger->register([['disk' => 'public', 'path' => 'second.txt']]);

    expect(Cache::get('filament-autosave:upload-ledger'))
        ->toHaveKeys([$first, $second]);
});

test('the ledger still works when the cache store does not support locking', function () {
    $store = new class implements Store
    {
        private array $data = [];

        public function get($key)
        {
            return $this->data[$key] ?? null;
        }

        public function many(array $keys)
        {
            return array_map(fn ($key) => $this->get($key), $keys);
        }

        public function put($key, $value, $seconds)
        {
            $this->data[$key] = $value;

            return true;
        }

        public function putMany(array $values, $seconds)
        {
            foreach ($values as $key => $value) {
                $this->put($key, $value, $seconds);
            }

            return true;
        }

        public function increment($key, $value = 1)
        {
            return $this->data[$key] = ($this->data[$key] ?? 0) + $value;
        }

        public function decrement($key, $value = 1)
        {
            return $this->increment($key, -$value);
        }

        public function forever($key, $value)
        {
            return $this->put($key, $value, 0);
        }

        public function forget($key)
        {
            unset($this->data[$key]);

            return true;
        }

        public function flush()
        {
            $this->data = [];

            return true;
        }

        public function getPrefix()
        {
            return '';
        }

        public function touch($key, $seconds)
        {
            return true;
        }
    };
    Cache::swap(new Repository($store));

    $ledger = app(AutosaveUploadLedger::class);
    $token = $ledger->register([['disk' => 'public', 'path' => 'no-lock.txt']]);

    expect(Cache::get('filament-autosave:upload-ledger'))->toHaveKey($token);
});
