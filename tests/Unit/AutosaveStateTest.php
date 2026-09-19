<?php

use Illuminate\Contracts\Support\Arrayable;
use Lenorix\FilamentAutosave\AutosaveState;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

test('normalize passes plain arrays through untouched', function () {
    expect(AutosaveState::normalize(['title' => 'x']))->toBe(['title' => 'x']);
});

test('normalize turns arrayable state into a plain array', function () {
    $state = new class implements Arrayable
    {
        public function toArray(): array
        {
            return ['title' => 'x'];
        }
    };

    expect(AutosaveState::normalize($state))->toBe(['title' => 'x']);
});

test('normalize reduces non-array state to an empty array', function () {
    expect(AutosaveState::normalize('nope'))->toBe([]);
});

test('upload stripping removes temporary files while keeping neighbours', function () {
    $upload = Mockery::mock(TemporaryUploadedFile::class);

    expect(AutosaveState::stripUploads([
        'title' => 'Hello',
        'avatar' => $upload,
        'attachments' => [$upload],
        'count' => 5,
    ]))->toBe(['title' => 'Hello', 'attachments' => [], 'count' => 5]);
});

test('upload stripping digs into repeater rows', function () {
    $upload = Mockery::mock(TemporaryUploadedFile::class);

    expect(AutosaveState::stripUploads([
        'title' => 'Post',
        'items' => [
            ['name' => 'a', 'photo' => $upload],
            ['name' => 'b'],
        ],
    ]))->toBe([
        'title' => 'Post',
        'items' => [
            ['name' => 'a'],
            ['name' => 'b'],
        ],
    ]);
});

test('upload stripping keeps a multi-file list a list', function () {
    $upload = Mockery::mock(TemporaryUploadedFile::class);

    $stripped = AutosaveState::stripUploads([
        'gallery' => [$upload, 'stored.jpg', $upload, 'other.jpg'],
        'keyed' => ['uuid-1' => $upload, 'uuid-2' => 'stored.jpg'],
    ]);

    // A JSON column would otherwise receive {"1":"stored.jpg"} instead of a list.
    expect($stripped['gallery'])->toBe(['stored.jpg', 'other.jpg'])
        ->and(json_encode($stripped['gallery']))->toBe('["stored.jpg","other.jpg"]')
        ->and($stripped['keyed'])->toBe(['uuid-2' => 'stored.jpg']);
});

test('upload stripping keeps scalars and non-upload objects', function () {
    $carbon = Carbon\Carbon::parse('2026-04-20');

    expect(AutosaveState::stripUploads([
        'title' => 'Hello',
        'count' => 42,
        'active' => true,
        'tags' => ['a', 'b'],
        'at' => $carbon,
    ]))->toEqual(['title' => 'Hello', 'count' => 42, 'active' => true, 'tags' => ['a', 'b'], 'at' => $carbon]);
});
