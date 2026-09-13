<?php

use Lenorix\FilamentAutosave\AutosaveFieldMap;

function autosaveStateField(bool $password = false, string $type = 'text'): object
{
    return new class($password, $type)
    {
        public function __construct(private bool $password, private string $type) {}

        public function isPassword(): bool
        {
            return $this->password;
        }

        public function getType(): string
        {
            return $this->type;
        }
    };
}

test('a field flagged as password is a secret', function () {
    expect(AutosaveFieldMap::isSecret([autosaveStateField(true)]))->toBeTrue();
});

test('a field typed as password is a secret even when not flagged', function () {
    expect(AutosaveFieldMap::isSecret([autosaveStateField(false, 'password')]))->toBeTrue();
});

test('plain fields are not secrets', function () {
    expect(AutosaveFieldMap::isSecret([autosaveStateField(false, 'text')]))->toBeFalse();
});
