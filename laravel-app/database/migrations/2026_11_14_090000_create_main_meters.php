<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Main meters: the PPWSA water meter and the EDC meter(s) the owner pays for.
 * Each month the reading is compared with the room meters behind it, so the owner sees
 * what was paid, what tenants were charged, and how much was used but not metered.
 * Rooms also get the owner-use status and their own EDC account number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('main_meters', function (Blueprint $table) {
            $table->id();
            $table->string('utility', 10);                          // electric or water
            $table->string('name', 60);                             // e.g. "PPWSA main", "EDC owner"
            $table->string('account', 40)->nullable();             // PPWSA / EDC customer number
            $table->string('tier_mode', 12)->default('whole');      // how the supplier charges: whole or progressive
            $table->decimal('extra_percent', 5, 2)->default(0);     // e.g. PPWSA sewage 10%
            $table->unsignedInteger('monthly_fee')->default(0);     // e.g. PPWSA meter fee 750 riel a month
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('main_meter_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('main_meter_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7);                              // e.g. 2026-11, read on the 28th
            $table->decimal('reading', 12, 2);
            $table->date('read_on');
            $table->decimal('usage_override', 10, 2)->nullable();   // when the supplier replaced the meter
            $table->string('note', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['main_meter_id', 'period']);
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->string('status', 20)->default('available')->change(); // adds owner_use
            $table->string('edc_account', 30)->nullable()->after('water_custom_rate');
            $table->foreignId('electric_main_meter_id')->nullable()->after('edc_account')->constrained('main_meters')->nullOnDelete();
            $table->foreignId('water_main_meter_id')->nullable()->after('electric_main_meter_id')->constrained('main_meters')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('electric_main_meter_id');
            $table->dropConstrainedForeignId('water_main_meter_id');
            $table->dropColumn('edc_account');
        });

        Schema::dropIfExists('main_meter_readings');
        Schema::dropIfExists('main_meters');
    }
};
