<?php

/**
 * Test files hold tests; fixtures hold classes. A class declared inside a test
 * file is only loaded when Pest happens to include that file first, which
 * makes cross-file references order-dependent.
 */
function testLayoutTopLevelDeclarations(string $path): array
{
    $source = preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($path));

    preg_match_all('/^(?:final |abstract |readonly )*(class|trait|enum|interface)\s+(\w+)/m', (string) $source, $matches, PREG_SET_ORDER);

    return array_map(fn (array $match): string => $match[2], $matches);
}

test('test suites declare no top-level classes', function () {
    $offenders = [];

    foreach (['Unit', 'Integration', 'Browser'] as $suite) {
        foreach (glob(dirname(__DIR__)."/{$suite}/*.php") as $path) {
            foreach (testLayoutTopLevelDeclarations($path) as $name) {
                $offenders[] = basename($path).': '.$name;
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('every fixture file declares exactly one class named after the file', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/Fixtures'));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/views/')) {
            continue;
        }

        $declared = testLayoutTopLevelDeclarations($file->getPathname());

        if ($declared !== [$file->getBasename('.php')]) {
            $offenders[] = $file->getBasename().' declares ['.implode(', ', $declared).']';
        }
    }

    expect($offenders)->toBe([]);
});

test('every fixture namespace mirrors its directory under tests/Fixtures', function () {
    $root = dirname(__DIR__).'/Fixtures';
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), '/views/')) {
            continue;
        }

        preg_match('/^namespace\s+([^;]+);/m', (string) file_get_contents($file->getPathname()), $match);
        $relative = trim(str_replace($root, '', dirname($file->getPathname())), '/');
        $expected = rtrim('Lenorix\\FilamentAutosave\\Tests\\Fixtures\\'.str_replace('/', '\\', $relative), '\\');

        if (($match[1] ?? null) !== $expected) {
            $offenders[] = $file->getBasename().': '.($match[1] ?? 'no namespace').' (expected '.$expected.')';
        }
    }

    expect($offenders)->toBe([]);
});
