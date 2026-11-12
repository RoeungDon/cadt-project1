<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The first create migration ran while its file was still empty,
     * so the table has no columns. Rebuild it only if "period" is missing.
     */
    public function up(): void
    {
        if (Schema::hasColumn('meter_readings', 'period')) {
            return; // table is already correct
        }

        Schema::dropIfExists('meter_readings');

        Schema::create('meter_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->char('period', 7); // billing month, e.g. 2026-11

            // Electricity (kWh)
            $table->decimal('electric_previous', 10, 2);
            $table->decimal('electric_current', 10, 2);
            $table->decimal('electric_usage', 10, 2);
            $table->unsignedInteger('electric_rate');       // average riel per kWh charged
            $table->unsignedBigInteger('electric_amount');  // riel

            // Water (m³)
            $table->decimal('water_previous', 10, 2);
            $table->decimal('water_current', 10, 2);
            $table->decimal('water_usage', 10, 2);
            $table->unsignedInteger('water_rate');          // average riel per m³ charged
            $table->unsignedBigInteger('water_amount');     // riel

            $table->unsignedBigInteger('total_amount');     // riel
            $table->date('read_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['room_id', 'period']); // one reading per room per month
        });
    }

    public function down(): void
    {
        // Nothing to undo: this migration only repairs the table structure.
    }
};