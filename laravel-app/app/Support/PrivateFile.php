<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Tenant documents and photos (ID cards, contracts, meter and repair photos).
 * Kept in storage/app/private; the API only hands out links that are signed and stop working
 * after LIFETIME_MINUTES, so a copied or leaked link is useless later.
 */
class PrivateFile
{
    public const DISK = 'local';

    public const LIFETIME_MINUTES = 120;

    public static function store(UploadedFile $file, string $folder): string
    {
        return $file->store($folder, self::DISK);
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /**
     * A signed link for a logged-in user who is allowed to see the file. $name is the file name for downloads.
     * The signature covers only the path and query (not the host), so it works behind a proxy or a new domain.
     */
    public static function url(?string $path, ?string $name = null): ?string
    {
        if (! $path) {
            return null;
        }

        $relative = URL::temporarySignedRoute(
            'private-file',
            now()->addMinutes(self::LIFETIME_MINUTES),
            array_filter(['path' => $path, 'name' => $name]),
            absolute: false,
        );

        return url($relative);
    }
}
