<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A meter replacement belongs to the tenant who was in the room when it was recorded,
     * so the next tenant is never charged for it. Each tenant can have their own replacement in a month.
     */
    public function up(): void
    {
        Schema::table('meter_replacements', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('room_id')->constrained()->nullOnDelete();
        });

        // Existing rows: the newest tenant of the room who already existed when the replacement was recorded
        DB::table('meter_replacements')->orderBy('id')->get()->each(function ($replacement) {
            $tenantId = DB::table('tenants')
                ->where('room_id', $replacement->room_id)
                ->where('created_at', '<=', $replacement->created_at)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->value('id');

            DB::table('meter_replacements')->where('id', $replacement->id)->update(['tenant_id' => $tenantId]);
        });

        // New index first: MySQL needs an index on room_id for its foreign key at all times
        Schema::table('meter_replacements', function (Blueprint $table) {
            $table->unique(['room_id', 'tenant_id', 'utility', 'period'], 'meter_replacements_room_tenant_utility_period_unique');
        });
        Schema::table('meter_replacements', function (Blueprint $table) {
            $table->dropUnique(['room_id', 'utility', 'period']);
        });
    }

    public function down(): void
    {
        // Keep one row per room, utility and month so the old unique index can be restored
        $keep = DB::table('meter_replacements')
            ->selectRaw('MAX(id) as id')
            ->groupBy('room_id', 'utility', 'period')
            ->pluck('id');
        DB::table('meter_replacements')->whereNotIn('id', $keep)->delete();

        Schema::table('meter_replacements', function (Blueprint $table) {
            $table->unique(['room_id', 'utility', 'period']);
        });
        Schema::table('meter_replacements', function (Blueprint $table) {
            $table->dropUnique('meter_replacements_room_tenant_utility_period_unique');
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
