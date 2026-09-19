# Upstream issue draft — pestphp/pest-plugin-browser

Ready to paste. Not filed automatically.

---

**Title:** In-process server never delivers multipart uploads to Laravel: `files[]` parts are dropped (5.0.1) or kept under a literal `files[]` key (5.x)

**Version:** `pestphp/pest-plugin-browser` v5.0.1 (Pest 5.2.1, Laravel 13, Livewire 4.4). Also reproduced on the `5.x` branch at `a78ede5`.

## Summary

`LaravelHttpServer::handleRequest()` builds the Symfony request itself. Any
browser upload — Livewire's temporary uploads, Filament `FileUpload`, a plain
`<input type="file" name="files[]">` form — therefore never reaches the app as
an uploaded file:

- **v5.0.1** only parses `application/x-www-form-urlencoded` bodies and passes
  `[] // @TODO files...` as the files array, so multipart bodies are ignored
  entirely.
- **5.x (unreleased)** adds `parseMultipartBody()`, but stores each file under
  the *literal* part name: `$files['files[]'] = new UploadedFile(...)`. PHP
  would populate `$_FILES['files'][0]`; Laravel's `$request->file('files')`
  returns `null`, `$request->allFiles()` has the key `"files[]"`. Livewire's
  `FileUploadController` then validates an empty set and answers
  `{"paths":[]}`, and FilePond sits in `processing` forever. The doc comment on
  `parseMultipartBody()` acknowledges the choice ("a nested name such as
  `files[avatar]` stays flat where PHP would nest") — but every Livewire
  upload uses `files[]`, so in practice no Livewire/Filament upload works.
- Once nesting is fixed, `removeUploads()` still assumes a flat list and calls
  `->getPathname()` on the nested array, turning the successful upload
  response into a 500.

## Minimal reproduction

```php
// routes/web.php (or any Livewire component using WithFileUploads)
Route::post('/probe', fn () => ['files' => array_map(fn ($f) => $f->getClientOriginalName(), (array) request()->file('files'))]);
```

```php
test('multipart files reach the app', function () {
    $page = visit('/'); // any page
    $page->script(<<<'JS'
        const fd = new FormData();
        fd.append('files[]', new File(['hello'], 'hello.txt', { type: 'text/plain' }));
        window.__r = null;
        fetch('/probe', { method: 'POST', body: fd, headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '' } })
            .then(r => r.text()).then(t => window.__r = t);
    JS);
    // poll window.__r …
    expect($page->script('window.__r'))->toContain('hello.txt'); // 5.0.1: {"files":[]}  5.x: {"files":[]}
});
```

A real-world equivalent: attach a file to a Filament `FileUpload` with
`$page->attach('input[type=file]', $path)`; the `livewire/upload-file` POST
returns `{"paths":[]}` and the field never leaves the uploading state.

## Proposed fix (verified locally on 5.x)

In `parseMultipartBody()`, nest file parts the way PHP does — reuse the same
`parse_str` shape already used for text fields:

```php
if (preg_match('/filename="([^"]*)"/', $rawHeaders, $fileName) === 1) {
    $path = (string) tempnam(sys_get_temp_dir(), 'pest-upload-');
    file_put_contents($path, $content);
    $mimeType = preg_match('/Content-Type:\s*([^\r\n]+)/i', $rawHeaders, $type) === 1 ? mb_trim($type[1]) : null;

    parse_str(rawurlencode($name[1]).'=1', $shape);
    array_walk_recursive($shape, function (&$leaf) use ($path, $fileName, $mimeType): void {
        $leaf = new UploadedFile($path, $fileName[1], $mimeType, null, true);
    });
    $files = array_merge_recursive($files, $shape);

    continue;
}
```

and make the cleanup tolerate nesting:

```php
private function removeUploads(array $files): void
{
    array_walk_recursive($files, function ($file): void {
        if ($file instanceof UploadedFile) {
            @unlink($file->getPathname());
        }
    });
}
```

With both changes applied to `5.x`, a Livewire 4 / Filament 5 `FileUpload`
round-trips end to end in this plugin (upload → temporary file → component
state → persisted path), including removal.

## Note for other users hitting this with Livewire

Livewire's `FileUploadConfiguration::disk()` returns `tmp-for-tests` whenever
`app()->runningUnitTests()` is true — which it is inside the plugin's
in-process server. That disk is not defined anywhere by default; define it in
your browser test case or uploads 500 with "Disk [tmp-for-tests] does not have
a configured driver" even after the fix above.
