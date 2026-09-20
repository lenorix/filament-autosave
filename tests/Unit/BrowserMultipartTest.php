<?php

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Lenorix\FilamentAutosave\Tests\Support\Browser\ParseMultipartUploads;

test('the browser bridge preserves binary uploads, repeated names and nested fields', function () {
    $boundary = 'browser-test-boundary';
    $binary = "\x00\xff\r\n bytes \r\n";
    $body = '';
    foreach (['first.bin' => $binary, 'second.txt' => 'second'] as $name => $content) {
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"files[]\"; filename=\"{$name}\"\r\nContent-Type: application/octet-stream\r\n\r\n{$content}\r\n";
    }
    $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"meta[label]\"\r\n\r\nexample\r\n--{$boundary}--\r\n";
    $request = Request::create('/upload', 'POST', server: ['CONTENT_TYPE' => 'multipart/form-data; boundary="'.$boundary.'"'], content: $body);
    $paths = [];

    (new ParseMultipartUploads)->handle($request, function (Request $request) use ($binary, &$paths) {
        $files = $request->file('files');
        expect($files)->toHaveCount(2)
            ->and($files[0]->isValid())->toBeTrue()
            ->and($files[0]->getContent())->toBe($binary)
            ->and($files[1]->getContent())->toBe('second')
            ->and($request->input('meta.label'))->toBe('example');
        $paths = array_map(fn ($file) => $file->getPathname(), $files);

        return response('ok');
    });

    foreach ($paths as $path) {
        expect(is_file($path))->toBeFalse();
    }
});

test('the browser bridge leaves files parsed by the server untouched', function () {
    $file = UploadedFile::fake()->create('native.txt');
    $request = Request::create('/upload', 'POST', files: ['files' => [$file]], server: ['CONTENT_TYPE' => 'multipart/form-data; boundary=test']);
    (new ParseMultipartUploads)->handle($request, function (Request $request) use ($file) {
        expect($request->file('files.0'))->toBe($file);

        return response('ok');
    });
    expect($file->isValid())->toBeTrue();
});

test('the browser bridge removes temporary files when the application throws', function () {
    $body = "--test\r\nContent-Disposition: form-data; name=\"files[]\"; filename=\"failed.txt\"\r\n\r\ncontent\r\n--test--\r\n";
    $request = Request::create('/upload', 'POST', server: ['CONTENT_TYPE' => 'multipart/form-data; boundary=test'], content: $body);
    $path = null;
    try {
        (new ParseMultipartUploads)->handle($request, function (Request $request) use (&$path) {
            $path = $request->file('files.0')->getPathname();
            throw new RuntimeException('application failure');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('application failure');
    }
    expect($path)->not->toBeNull()
        ->and(is_file($path))->toBeFalse();
});
