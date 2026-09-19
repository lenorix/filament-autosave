<?php

namespace Lenorix\FilamentAutosave;

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;

class AutosaveStore
{
    public const CACHE_PREFIX = 'filament-autosave';

    /** JSON flags shared by hashes and undo snapshots. */
    public const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** @param  array<string, mixed>  $data */
    public function snapshotHash(array $data): string
    {
        // Preserve nested order so moved repeater rows count as changes.
        ksort($data);

        try {
            $encoded = json_encode($data, self::JSON_FLAGS);
        } catch (\JsonException) {
            // Fallback serialization keeps invalid values distinguishable.
            $encoded = serialize($data);
        }

        return hash('xxh128', $encoded);
    }

    /**
     * Round-trip values through JSON so dates and enums become plain scalars.
     *
     * @template TSnapshot of array<array-key, mixed>
     *
     * @param  TSnapshot  $data
     * @return TSnapshot
     */
    public static function normalizeScalars(array $data): array
    {
        try {
            return json_decode(
                json_encode($data, self::JSON_FLAGS),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable) {
            return $data;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string>  $except
     * @return array<string, mixed>
     */
    public function excludeFields(array $data, array $except): array
    {
        return array_diff_key($data, array_flip($except));
    }

    public function cacheKey(string $pageClass): string
    {
        return self::CACHE_PREFIX.':'.$this->currentScope().':'.$pageClass;
    }

    /**
     * An Undo target belongs to one live component: two tabs of the same
     * user on the same record must not share a slot, or one tab's autosave
     * overwrites the other's snapshot and its Undo restores the wrong value.
     */
    public function undoCacheKey(string $pageClass, int|string|null $recordKey = null, ?string $instanceId = null): string
    {
        return self::CACHE_PREFIX.':undo:'.$this->currentScope().':'.$pageClass.':'.($recordKey ?? 'default')
            .(filled($instanceId) ? ':'.$instanceId : '');
    }

    /** Scope drafts and undo snapshots by tenant and owner. */
    public function currentScope(): string
    {
        // Keep raw session IDs out of cache keys.
        $owner = $this->resolveOwnerId() ?? 'guest-'.hash('xxh128', session()->getId());
        $tenant = Filament::getTenant()?->getKey();

        return ($tenant !== null ? $tenant.':' : '').$owner;
    }

    private function resolveOwnerId(): ?string
    {
        $panel = Filament::getCurrentPanel();

        $id = $panel?->auth()?->id() ?? auth()->id();

        if ($id === null) {
            return null;
        }

        // Include the guard because panels may reuse user IDs.
        $guard = $panel?->getAuthGuard() ?? config('auth.defaults.guard');

        return $guard.':'.$id;
    }

    /** @param  array<string, mixed>  $data */
    public function storeDraft(string $key, array $data, int $ttlHours): void
    {
        Cache::put($key, $data, now()->addHours($ttlHours));
    }

    /** @return array<string, mixed>|null */
    public function restoreDraft(string $key): ?array
    {
        $draft = Cache::get($key);

        return is_array($draft) ? $draft : null;
    }

    public function clearDraft(string $key): void
    {
        Cache::forget($key);
    }
}
