<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Replace the single electricity/water rates with tiered tariffs.
     * "up_to" is the highest usage of the tier (null = no upper limit).
     */
    public function up(): void
    {
        $values = [
            'tier_mode'      => 'whole', // "whole" or "progressive"
            'electric_tiers' => json_encode([
                ['up_to' => 10, 'rate' => 380],
                ['up_to' => 50, 'rate' => 480],
                ['up_to' => 200, 'rate' => 610],
                ['up_to' => null, 'rate' => 730],
            ]),
            'water_tiers'    => json_encode([
                ['up_to' => 7, 'rate' => 400],
                ['up_to' => 15, 'rate' => 720],
                ['up_to' => 25, 'rate' => 960],
                ['up_to' => 50, 'rate' => 1250],
                ['up_to' => 100, 'rate' => 1900],
                ['up_to' => null, 'rate' => 2200],
            ]),
        ];

        foreach ($values as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        // The old single rates are no longer used
        DB::table('settings')->whereIn('key', ['electric_rate', 'water_rate'])->delete();
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['tier_mode', 'electric_tiers', 'water_tiers'])->delete();
    }
};