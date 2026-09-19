<?php

use Lenorix\FilamentAutosave\HasAutosave;
use Lenorix\FilamentAutosave\HasAutosaveBase;
use Lenorix\FilamentAutosave\HasAutosaveDraft;
use Lenorix\FilamentAutosave\HasAutosaveForCreate;
use Lenorix\FilamentAutosave\HasAutosaveForForm;
use Lenorix\FilamentAutosave\HasAutosaveUploads;

/**
 * The stable extension surface. Adding or removing an entry here is a
 * deliberate, documented API change; everything else in the traits is
 * internal and may change in a minor release.
 */
const AUTOSAVE_API = [
    // Overridable hooks (protected)
    'afterAutosave',
    'autosaveDebounce',
    'autosaveExcept',
    'autosaveMergeFields',
    'autosavePollInterval',
    'beforeAutosave',
    'getAutosaveFormContext',
    'getAutosaveStatePath',
    'getAutosaveValidationRules',
    'getUndoTtlMinutes',
    'persistAutosaveForm',
    'resolveAutosaveForm',
    'shouldAutosave',
    // Callable entry points (public)
    'autosave',
    'clearAutosaveDraft',
    'discardDraft',
    'flushAutosave',
    'getAutosaveDebounce',
    'getAutosaveExcept',
    'getAutosaveMergeFields',
    'getAutosavePollInterval',
    'isAutosaveEnabled',
    'restoreDraft',
    'syncAutosave',
    'undoAutosave',
];

function autosaveTraitMethods(): array
{
    $methods = [];

    foreach ([
        HasAutosaveBase::class, HasAutosave::class, HasAutosaveForForm::class,
        HasAutosaveForCreate::class, HasAutosaveDraft::class, HasAutosaveUploads::class,
    ] as $trait) {
        foreach ((new ReflectionClass($trait))->getMethods() as $method) {
            $methods[$method->getName()][] = $method;
        }
    }

    return $methods;
}

test('every documented extension point is tagged @api', function () {
    $tagged = [];

    foreach (autosaveTraitMethods() as $name => $methods) {
        foreach ($methods as $method) {
            if (str_contains((string) $method->getDocComment(), '@api')) {
                $tagged[$name] = true;
            }
        }
    }

    $tagged = array_keys($tagged);
    sort($tagged);
    $expected = AUTOSAVE_API;
    sort($expected);

    expect($tagged)->toBe($expected);
});

test('an @api method keeps the same tag on every trait that declares it', function () {
    foreach (autosaveTraitMethods() as $name => $methods) {
        if (! in_array($name, AUTOSAVE_API, true) || count($methods) < 2) {
            continue;
        }

        foreach ($methods as $method) {
            expect((string) $method->getDocComment())
                ->toContain('@api');
        }
    }
});

test('public lifecycle methods Livewire calls are marked @internal, not @api', function () {
    foreach (autosaveTraitMethods() as $name => $methods) {
        if (! str_starts_with($name, 'mountHasAutosave') && ! str_starts_with($name, 'dehydrateHasAutosave')) {
            continue;
        }

        foreach ($methods as $method) {
            expect((string) $method->getDocComment())->toContain('@internal');
        }
    }
});
