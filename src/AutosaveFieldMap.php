<?php

namespace Lenorix\FilamentAutosave;

class AutosaveFieldMap
{
    /**
     * Whether any field in the set stores sensitive data.
     *
     * @param  array<int, mixed>  $fields
     */
    public static function isSecret(array $fields): bool
    {
        return static::anyMatching($fields, static fn (object $field): bool => (method_exists($field, 'isPassword') && $field->isPassword())
            || (method_exists($field, 'getType') && $field->getType() === 'password')
        );
    }

    /**
     * Whether any field answers the given method with a truthy value.
     *
     * @param  array<int, mixed>  $fields
     */
    public static function any(array $fields, string $method): bool
    {
        return static::anyMatching($fields, static fn (object $field): bool => method_exists($field, $method) && (bool) $field->{$method}()
        );
    }

    /**
     * @param  array<int, mixed>  $fields
     */
    private static function anyMatching(array $fields, callable $matches): bool
    {
        foreach ($fields as $field) {
            if ($matches($field)) {
                return true;
            }
        }

        return false;
    }
}
