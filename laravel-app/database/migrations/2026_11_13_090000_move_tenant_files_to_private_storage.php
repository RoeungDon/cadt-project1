<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Moves tenant photos, ID cards / contracts, meter photos and repair photos
 * from the public folder (anyone with the link could open them) to storage/app/private.
 * The paths in the database stay the same; only the folder changes. Safe to run twice.
 */
return new class extends Migration
{
    // table => columns that hold a file path
    private const COLUMNS = [
        'tenants'              => ['photo_path'],
        'tenant_documents'     => ['path'],
        'meter_readings'       => ['electric_photo_path', 'water_photo_path'],
        'maintenance_requests' => ['photo_path', 'after_photo_path', 'receipt_photo_path'],
    ];

    public function up(): void
    {
        $this->moveAll('public', 'local');
    }

    public function down(): void
    {
        $this->moveAll('local', 'public');
    }

    private function moveAll(string $from, string $to): void
    {
        $source = Storage::disk($from);
        $target = Storage::disk($to);
        $moved = 0;

        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $column) {
                $paths = DB::table($table)->whereNotNull($column)->where($column, '!=', '')->pluck($column);

                foreach ($paths as $path) {
                    if (! $source->exists($path)) {
                        continue; // already moved, or the file was lost before
                    }

                    if (! $target->exists($path)) {
                        $stream = $source->readStream($path);
                        $target->writeStream($path, $stream);
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }

                    if ($target->exists($path)) {
                        $source->delete($path);
                        $moved++;
                    }
                }
            }
        }

        Log::info("Tenant files moved from {$from} to {$to} storage: {$moved}");
    }
};
