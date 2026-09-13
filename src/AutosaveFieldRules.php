<?php

namespace Lenorix\FilamentAutosave;

use Filament\Forms\Components\BaseFileUpload;
use Illuminate\Support\Facades\Validator;

class AutosaveFieldRules
{
    /**
     * Collect the validation rules declared by each Filament field.
     *
     * @param  array<string, array<int, object>>  $fields
     * @return array<string, array<int, mixed>>
     */
    public static function componentRules(array $fields): array
    {
        $rules = [];

        foreach ($fields as $path => $fieldSet) {
            foreach ($fieldSet as $field) {
                // Uploads validate their temporary/stored state in the upload
                // lifecycle. Their general rules expect the temporary array,
                // while dehydrated column state may already be a stored path.
                if ($field instanceof BaseFileUpload || ! method_exists($field, 'getValidationRules')) {
                    continue;
                }

                $fieldRules = $field->getValidationRules();

                if ($fieldRules === []) {
                    continue;
                }

                $rules[$path] = [
                    ...($rules[$path] ?? []),
                    ...$fieldRules,
                ];
            }
        }

        return $rules;
    }

    /**
     * Combine component and page rules without allowing one set to replace the other.
     *
     * @param  array<string, mixed>  ...$ruleSets
     * @return array<string, array<int, mixed>>
     */
    public static function merge(array ...$ruleSets): array
    {
        $merged = [];

        foreach ($ruleSets as $ruleSet) {
            foreach ($ruleSet as $path => $rules) {
                $merged[$path] = [
                    ...($merged[$path] ?? []),
                    ...(is_array($rules) ? $rules : [$rules]),
                ];
            }
        }

        return $merged;
    }

    /**
     * Enforce each option field keep only values it actually offers.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, array<int, mixed>>  $fields
     * @return array<string, mixed>
     */
    public static function enforceOptionRules(array $data, array $fields, ?array &$errors = null): array
    {
        AutosaveFieldTree::eachMatch(
            $data,
            $fields,
            function (array &$data, array $fieldSet, string $match): void {
                if (! filled(data_get($data, $match))) {
                    return;
                }

                $rules = [];

                foreach ($fieldSet as $field) {
                    if (! method_exists($field, 'getInValidationRule')) {
                        continue;
                    }

                    if (($rule = $field->getInValidationRule()) !== null) {
                        $rules[] = $rule;
                    }
                }

                if ($rules !== [] && ! self::passesOptionRules(data_get($data, $match), $rules)) {
                    $errors ??= [];
                    $errors[$match] = ['The selected value is invalid.'];
                    AutosaveFieldTree::forget($data, $match);
                }
            },
        );

        return $data;
    }

    public static function passesOptionRules(mixed $value, array $rules): bool
    {
        $constraints = is_array($value)
            ? ['value' => ['array'], 'value.*' => $rules]
            : ['value' => $rules];

        return Validator::make(['value' => $value], $constraints)->passes();
    }

    /**
     * Filter the page rules to the fields present, then validate the data.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public static function applyRules(array $fields, array $rules, ?array &$errors = null): array
    {
        $applicable = [];

        foreach ($rules as $key => $rule) {
            if (array_key_exists(explode('.', (string) $key, 2)[0], $fields)) {
                $applicable[$key] = $rule;
            }
        }

        if (empty($applicable)) {
            return $fields;
        }

        $validator = Validator::make($fields, $applicable);

        if (! $validator->fails()) {
            return $fields;
        }

        $errors = $validator->errors()->toArray();

        foreach (array_keys($validator->failed()) as $failed) {
            unset($fields[explode('.', (string) $failed, 2)[0]]);
        }

        return $fields;
    }
}
