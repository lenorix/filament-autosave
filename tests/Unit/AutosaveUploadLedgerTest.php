<?php

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
