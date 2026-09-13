<?php

use Lenorix\FilamentAutosave\AutosaveFieldRules;

function autosaveRuleField(?string $rule = null): object
{
    return new class($rule)
    {
        public function __construct(private ?string $rule) {}

        public function getInValidationRule(): ?string
        {
            return $this->rule;
        }
    };
}

test('enforce option rules keeps values that match the field options', function () {
    $data = ['status' => 'a'];
    $fields = ['status' => [autosaveRuleField('in:a,b')]];

    expect(AutosaveFieldRules::enforceOptionRules($data, $fields))->toBe(['status' => 'a']);
});

test('enforce option rules drops values not offered by the field', function () {
    $data = ['status' => 'zzz'];
    $fields = ['status' => [autosaveRuleField('in:a,b')]];

    expect(AutosaveFieldRules::enforceOptionRules($data, $fields))->toBe([]);
});

test('enforce option rules checks every selection inside an array', function () {
    $data = ['tags' => ['a', 'zzz']];
    $fields = ['tags' => [autosaveRuleField('in:a,b')]];

    expect(AutosaveFieldRules::enforceOptionRules($data, $fields))->toBe([]);
});

test('enforce option rules leaves blank values untouched', function () {
    $data = ['status' => null];
    $fields = ['status' => [autosaveRuleField('in:a,b')]];

    expect(AutosaveFieldRules::enforceOptionRules($data, $fields))->toBe(['status' => null]);
});

test('enforce option rules skips fields that build no option rule', function () {
    $data = ['status' => 'anything'];
    $fields = ['status' => [autosaveRuleField(null)]];

    expect(AutosaveFieldRules::enforceOptionRules($data, $fields))->toBe(['status' => 'anything']);
});

test('validation filters rules down to the fields actually present', function () {
    $fields = ['title' => 'Hello'];

    expect(AutosaveFieldRules::applyRules($fields, ['title' => 'string']))->toBe(['title' => 'Hello']);
});

test('validation drops the whole top-level field when a nested rule fails', function () {
    $fields = ['settings' => ['name' => '']];

    expect(AutosaveFieldRules::applyRules($fields, ['settings.name' => 'required']))->toBe([]);
});

test('validation leaves rules for absent fields out', function () {
    $fields = ['other' => 1];

    expect(AutosaveFieldRules::applyRules($fields, ['title' => 'required']))->toBe(['other' => 1]);
});

test('validation ignores rules that pass', function () {
    $fields = ['title' => 'Hello'];

    expect(AutosaveFieldRules::applyRules($fields, ['title' => 'required']))->toBe(['title' => 'Hello']);
});
