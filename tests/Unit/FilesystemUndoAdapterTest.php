<?php

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Lenorix\FilamentAutosave\ExternalUndoAdapters\FilesystemUndoAdapter;

/*
 * Unit-level coverage for FilesystemUndoAdapter::restore() failure handling,
 * exercised directly against a field/disk pair rather than through a full
 * autosave/Livewire cycle (see tests/Integration/FilesystemUndoAdapterTest.php
 * for the end-to-end scenarios).
 */

test('a disk write failure during restore is surfaced rather than silently ignored', function () {
    Storage::fake('public');
    Storage::disk('public')->put('body/old.txt', 'old content');
    Storage::disk('public')->put('body/new.txt', 'new content');

    $failingDisk = new class implements Filesystem
    {
        public function path($path)
        {
            return Storage::disk('public')->path($path);
        }

        public function exists($path)
        {
            return Storage::disk('public')->exists($path);
        }

        public function get($path)
        {
            return Storage::disk('public')->get($path);
        }

        public function readStream($path)
        {
            return Storage::disk('public')->readStream($path);
        }

        public function put($path, $contents, $options = [])
        {
            return false;
        }

        public function putFile($path, $file = null, $options = [])
        {
            return Storage::disk('public')->putFile($path, $file, $options);
        }

        public function putFileAs($path, $file, $name = null, $options = [])
        {
            return Storage::disk('public')->putFileAs($path, $file, $name, $options);
        }

        public function writeStream($path, $resource, array $options = [])
        {
            return Storage::disk('public')->writeStream($path, $resource, $options);
        }

        public function getVisibility($path)
        {
            return Storage::disk('public')->getVisibility($path);
        }

        public function setVisibility($path, $visibility)
        {
            return Storage::disk('public')->setVisibility($path, $visibility);
        }

        public function prepend($path, $data)
        {
            return Storage::disk('public')->prepend($path, $data);
        }

        public function append($path, $data)
        {
            return Storage::disk('public')->append($path, $data);
        }

        public function delete($paths)
        {
            return Storage::disk('public')->delete($paths);
        }

        public function copy($from, $to)
        {
            return Storage::disk('public')->copy($from, $to);
        }

        public function move($from, $to)
        {
            return Storage::disk('public')->move($from, $to);
        }

        public function size($path)
        {
            return Storage::disk('public')->size($path);
        }

        public function lastModified($path)
        {
            return Storage::disk('public')->lastModified($path);
        }

        public function files($directory = null, $recursive = false)
        {
            return Storage::disk('public')->files($directory, $recursive);
        }

        public function allFiles($directory = null)
        {
            return Storage::disk('public')->allFiles($directory);
        }

        public function directories($directory = null, $recursive = false)
        {
            return Storage::disk('public')->directories($directory, $recursive);
        }

        public function allDirectories($directory = null)
        {
            return Storage::disk('public')->allDirectories($directory);
        }

        public function makeDirectory($path)
        {
            return Storage::disk('public')->makeDirectory($path);
        }

        public function deleteDirectory($directory)
        {
            return Storage::disk('public')->deleteDirectory($directory);
        }
    };

    $field = new class($failingDisk)
    {
        public function __construct(private Filesystem $disk) {}

        public function getDisk(): Filesystem
        {
            return $this->disk;
        }

        public function getRawState(): array
        {
            return ['body/new.txt'];
        }
    };

    $adapter = new FilesystemUndoAdapter('public');
    $snapshot = ['paths' => ['body/old.txt'], 'contents' => ['body/old.txt' => 'old content']];

    expect(fn () => $adapter->restore($field, $snapshot))
        ->toThrow(RuntimeException::class, 'Could not restore [body/old.txt] on disk [public].');

    // The new file (not in the snapshot) is still removed before the failed
    // write is attempted; the failure is surfaced rather than swallowed, but
    // it does not stop the adapter from clearing what it already knows is
    // safe to delete.
    Storage::disk('public')->assertMissing('body/new.txt');
});
