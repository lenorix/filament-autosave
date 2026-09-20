<?php

namespace Lenorix\FilamentAutosave;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Durable best-effort journal for files written before a database commit.
 *
 * The journal closes the crash window that request-local cleanup cannot cover:
 * a later pruning run removes entries left behind by an interrupted request.
 *
 * Every entry lives in one shared index, so every mutation is guarded by an
 * atomic lock: two concurrent autosaves reading, modifying, and writing that
 * index back without one would silently overwrite each other's tokens.
 */
final class AutosaveUploadLedger
{
    private const INDEX_KEY = 'filament-autosave:upload-ledger';

    private const LOCK_KEY = self::INDEX_KEY.':lock';

    /** @param array<int, array{disk:string,path:string}> $files */
    public function register(array $files, ?int $ttlMinutes = null): string
    {
        $token = (string) Str::uuid();
        $expiresAt = now()->addMinutes($ttlMinutes ?? $this->entryTtl())->timestamp;

        $this->withLock(function () use ($token, $files, $expiresAt): void {
            $entries = $this->entries();
            $entries[$token] = ['files' => $files, 'expires_at' => $expiresAt];
            $this->store($entries);
        });

        return $token;
    }

    /**
     * Add files to an existing entry, keeping its expiry. A token that no
     * longer exists is re-created rather than dropped: losing the journal
     * entry is the one outcome this class exists to prevent.
     *
     * @param  array<int, array{disk:string,path:string}>  $files
     */
    public function append(string $token, array $files): void
    {
        if ($files === []) {
            return;
        }

        $this->withLock(function () use ($token, $files): void {
            $entries = $this->entries();
            $entry = $entries[$token] ?? [
                'files' => [],
                'expires_at' => now()->addMinutes($this->entryTtl())->timestamp,
            ];
            $entry['files'] = array_values(array_unique([...$entry['files'], ...$files], SORT_REGULAR));
            $entries[$token] = $entry;
            $this->store($entries);
        });
    }

    public function commit(string $token): void
    {
        $this->forget($token);
    }

    public function rollback(string $token): void
    {
        $entry = null;

        $this->withLock(function () use ($token, &$entry): void {
            $entry = $this->entries()[$token] ?? null;
        });

        if (is_array($entry)) {
            foreach ($entry['files'] as $file) {
                try {
                    Storage::disk($file['disk'])->delete($file['path']);
                } catch (\Throwable) {
                    // Pruning can retry providers that are temporarily offline.
                }
            }
        }

        $this->forget($token);
    }

    public function forget(string $token): void
    {
        $this->withLock(function () use ($token): void {
            $entries = $this->entries();
            unset($entries[$token]);

            if ($entries === []) {
                Cache::forget(self::INDEX_KEY);

                return;
            }

            $this->store($entries);
        });
    }

    public function prune(?int $now = null): int
    {
        $now ??= now()->timestamp;
        $removed = 0;

        foreach ($this->entries() as $token => $entry) {
            if ($entry['expires_at'] > $now) {
                continue;
            }

            $this->rollback((string) $token);
            $removed++;
        }

        return $removed;
    }

    /** `upload_ledger_ttl` has no fluent method on `AutosavePlugin`, config only, as documented in the README. */
    private function entryTtl(): int
    {
        return max(1, (int) config('filament-autosave.upload_ledger_ttl', 180));
    }

    /**
     * The index must outlive its entries: an entry becomes prunable when its
     * TTL elapses, and pruning can only see it while the index is still
     * cached. Twice the longest entry lifetime leaves a whole pruning window.
     *
     * @param  array<string, array{files:array<int, array{disk:string,path:string}>,expires_at:int}>  $entries
     */
    private function store(array $entries): void
    {
        $latest = max([now()->timestamp, ...array_column($entries, 'expires_at')]);
        $minutes = max($this->entryTtl(), (int) ceil(($latest - now()->timestamp) / 60));

        Cache::put(self::INDEX_KEY, $entries, now()->addMinutes(2 * $minutes));
    }

    /** @return array<string, array{files:array<int, array{disk:string,path:string}>,expires_at:int}> */
    private function entries(): array
    {
        $entries = Cache::get(self::INDEX_KEY, []);

        return is_array($entries) ? $entries : [];
    }

    /**
     * Serialize read-modify-write access to the shared index. Falls back to
     * running unguarded on a cache store without lock support, and proceeds
     * without the lock rather than failing autosave if one can't be acquired
     * promptly: the journal is a best-effort backstop, not the source of truth.
     */
    private function withLock(callable $callback): void
    {
        $store = Cache::getStore();

        if (! $store instanceof LockProvider) {
            $callback();

            return;
        }

        /** @var Lock $lock */
        $lock = Cache::lock(self::LOCK_KEY, 5);

        try {
            $lock->block(2, $callback);
        } catch (LockTimeoutException) {
            $callback();
        }
    }
}
