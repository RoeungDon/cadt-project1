<?php

namespace App\Http\Controllers;

use App\Support\PrivateFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\PathTraversalDetected;

// GET /api/files/{path}?expires=…&signature=…   only links made by PrivateFile::url() open
class PrivateFileController extends Controller
{
    public function __invoke(Request $request, string $path)
    {
        try {
            $disk = Storage::disk(PrivateFile::DISK);
            abort_unless($disk->exists($path), 404);

            $mime = $disk->mimeType($path) ?: 'application/octet-stream';
            $name = str_replace(['"', "\r", "\n", '/', '\\'], '', (string) $request->query('name', basename($path)));

            $headers = [
                'Content-Type'           => $mime,
                'Cache-Control'          => 'private, max-age=3600',
                'X-Content-Type-Options' => 'nosniff',
                'Referrer-Policy'        => 'no-referrer',
            ];

            // Pictures and PDFs open in the browser; anything else is only downloaded
            $inline = str_starts_with($mime, 'image/') || $mime === 'application/pdf';

            if ($mime !== 'application/pdf') {
                $headers['Content-Security-Policy'] = "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox";
            }

            return $disk->response($path, $name, $headers, $inline ? 'inline' : 'attachment');
        } catch (PathTraversalDetected) {
            abort(404);
        }
    }
}
