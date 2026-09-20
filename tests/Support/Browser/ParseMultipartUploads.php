<?php

namespace Lenorix\FilamentAutosave\Tests\Support\Browser;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/** Decode real browser uploads omitted by Pest's in-process HTTP server. Test-only. */
final class ParseMultipartUploads
{
    public function handle(Request $request, Closure $next): mixed
    {
        $type = $request->header('Content-Type', '');

        if ($request->files->count() || ! preg_match('/multipart\/form-data;.*boundary=(?:"([^"]+)"|([^;\s]+))/i', $type, $match)) {
            return $next($request);
        }

        $boundary = $match[1] ?: $match[2];
        $paths = [];
        $files = [];
        $parameters = [];

        try {
            foreach (explode('--'.$boundary, $request->getContent()) as $part) {
                if (! str_starts_with($part, "\r\n") || ! str_contains($part, "\r\n\r\n")) {
                    continue;
                }

                [$headers, $body] = explode("\r\n\r\n", substr($part, 2), 2);
                $body = preg_replace('/\r\n$/', '', $body);

                if (! preg_match('/\bname="([^"]+)"/', $headers, $name)) {
                    continue;
                }

                parse_str(rawurlencode($name[1]).'=1', $shape);

                if (preg_match('/\bfilename="([^"]*)"/', $headers, $filename)) {
                    $path = tempnam(sys_get_temp_dir(), 'autosave-browser-');
                    $paths[] = $path;
                    file_put_contents($path, $body);
                    $mime = preg_match('/Content-Type:\s*([^\r\n]+)/i', $headers, $contentType) ? trim($contentType[1]) : null;
                    array_walk_recursive($shape, function (&$leaf) use ($path, $filename, $mime): void {
                        $leaf = new UploadedFile($path, $filename[1], $mime, null, true);
                    });
                    $files = array_merge_recursive($files, $shape);
                } else {
                    array_walk_recursive($shape, function (&$leaf) use ($body): void {
                        $leaf = $body;
                    });
                    $parameters = array_merge_recursive($parameters, $shape);
                }
            }

            $request->files->add($files);
            $request->request->add($parameters);

            return $next($request);
        } finally {
            foreach ($paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }
}
