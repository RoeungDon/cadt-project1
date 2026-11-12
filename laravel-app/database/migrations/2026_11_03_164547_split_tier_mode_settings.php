<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * One charging method for each utility:
     * electricity = whole usage at its tier rate, water = progressive tiers.
     */
    public function up(): void
    {
        $values = [
            'electric_tier_mode' => 'whole',
            'water_tier_mode'    => 'progressive',
        ];

        foreach ($values as $key => $value) {
            DB::table('settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        DB::table('settings')->where('key', 'tier_mode')->delete();
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['electric_tier_mode', 'water_tier_mode'])->delete();

        DB::table('settings')->updateOrInsert(
            ['key' => 'tier_mode'],
            ['value' => 'whole', 'created_at' => now(), 'updated_at' => now()],
        );
    }
};