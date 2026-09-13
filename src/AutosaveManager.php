<?php

namespace Lenorix\FilamentAutosave;

/**
 * Static facade over the AutosaveStore, kept for backward compatibility.
 */
class AutosaveManager
{
    /** @param  array<string, mixed>  $data */
    public static function snapshotHash(array $data): string
    {
        return static::store()->snapshotHash($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string>  $except
     * @return array<string, mixed>
     */
    public static function excludeFields(array $data, array $except): array
    {
        return static::store()->excludeFields($data, $except);
    }

    public static function cacheKey(string $pageClass): string
    {
        return static::store()->cacheKey($pageClass);
    }

    public static function currentScope(): string
    {
        return static::store()->currentScope();
    }

    /** @param  array<string, mixed>  $data */
    public static function storeDraft(string $key, array $data, int $ttlHours): void
    {
        static::store()->storeDraft($key, $data, $ttlHours);
    }

    /** @return array<string, mixed>|null */
    public static function restoreDraft(string $key): ?array
    {
        return static::store()->restoreDraft($key);
    }

    public static function clearDraft(string $key): void
    {
        static::store()->clearDraft($key);
    }

    public static function store(): AutosaveStore
    {
        return app(AutosaveStore::class);
    }
}
