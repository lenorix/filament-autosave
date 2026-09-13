<?php

namespace Lenorix\FilamentAutosave;

use Illuminate\Contracts\Support\Arrayable;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class AutosaveState
{
    /**
     * Normalize any form state value into a plain array.
     */
    public static function normalize(mixed $state): array
    {
        if ($state instanceof Arrayable) {
            $state = $state->toArray();
        }

        return is_array($state) ? $state : [];
    }

    /**
     * Remove temporary uploads from a payload before it is stored.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function stripUploads(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof TemporaryUploadedFile) {
                unset($data[$key]);
            } elseif (is_array($value) && self::hasUploads($value)) {
                $data[$key] = self::stripUploads($value);
            }
        }

        return $data;
    }

    private static function hasUploads(array $value): bool
    {
        foreach ($value as $item) {
            if ($item instanceof TemporaryUploadedFile) {
                return true;
            }

            if (is_array($item) && self::hasUploads($item)) {
                return true;
            }
        }

        return false;
    }
}
