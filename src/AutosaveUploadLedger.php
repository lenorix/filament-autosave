<?php

namespace Lenorix\FilamentAutosave;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Durable best-effort journal for files written before a database commit.
 *
 * The journal closes the crash window that request-local cleanup cannot cover:
 * a later pruning run removes entries left behind by an interrupted request.
 */
final class AutosaveUploadLedger
{
    private const INDEX_KEY = 'filament-autosave:upload-ledger';

    /** @param array<int, array{disk:string,path:string}> $files */
    public function register(array $files, ?int $ttlMinutes = null): string
    {
        $token = (string) Str::uuid();
        $entries = $this->entries();
        $expiresAt = now()->addMinutes($ttlMinutes ?? (int) config('filament-autosave.upload_ledger_ttl', 180))->timestamp;

        $entries[$token] = ['files' => $files, 'expires_at' => $expiresAt];
        Cache::put(self::INDEX_KEY, $entries, now()->addMinutes(max(1, (int) config('filament-autosave.upload_ledger_ttl', 180))));

        return $token;
    }

    public function commit(string $token): void
    {
        $this->forget($token);
    }

    public function rollback(string $token): void
    {
        $entries = $this->entries();
        $entry = $entries[$token] ?? null;

        if (is_array($entry)) {
            foreach ($entry['files'] ?? [] as $file) {
                if (is_string($file['disk'] ?? null) && is_string($file['path'] ?? null)) {
                    try {
                        Storage::disk($file['disk'])->delete($file['path']);
                    } catch (\Throwable) {
                        // Pruning can retry providers that are temporarily offline.
                    }
                }
            }
        }

        $this->forget($token);
    }

    public function forget(string $token): void
    {
        $entries = $this->entries();
        unset($entries[$token]);

        if ($entries === []) {
            Cache::forget(self::INDEX_KEY);

            return;
        }

        Cache::put(self::INDEX_KEY, $entries, now()->addMinutes(max(1, (int) config('filament-autosave.upload_ledger_ttl', 180))));
    }

    public function prune(?int $now = null): int
    {
        $now ??= now()->timestamp;
        $removed = 0;

        foreach ($this->entries() as $token => $entry) {
            if (($entry['expires_at'] ?? PHP_INT_MAX) > $now) {
                continue;
            }

            $this->rollback((string) $token);
            $removed++;
        }

        return $removed;
    }

    /** @return array<string, array{files:array<int, array{disk:string,path:string}>,expires_at:int}> */
    private function entries(): array
    {
        $entries = Cache::get(self::INDEX_KEY, []);

        return is_array($entries) ? $entries : [];
    }
}
