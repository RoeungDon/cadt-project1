<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Utilities upgrade:
     * - custom rate for each room (method C, overrides tiers A/B)
     * - charging method, meter photos and lock on each monthly reading
     * - meter replacements
     * - minimum charge and unusual-usage warning settings
     * Every step checks first, so it is safe to run even if part of it already exists.
     */
    public function up(): void
    {
        // 1. Custom rates on rooms (method C)
        if (! Schema::hasColumn('rooms', 'electric_rate_type')) {
            Schema::table('rooms', function (Blueprint $table) {
                $table->string('electric_rate_type', 20)->nullable()->after('rent_price');        // per_unit or fixed
                $table->unsignedInteger('electric_custom_rate')->nullable()->after('electric_rate_type'); // riel
                $table->string('water_rate_type', 20)->nullable()->after('electric_custom_rate');
                $table->unsignedInteger('water_custom_rate')->nullable()->after('water_rate_type');
            });
        }

        // 2. New columns on meter readings
        Schema::table('meter_readings', function (Blueprint $table) {
            if (! Schema::hasColumn('meter_readings', 'electric_method')) {
                $table->string('electric_method', 20)->nullable()->after('electric_amount'); // whole, progressive, per_unit, fixed
            }
            if (! Schema::hasColumn('meter_readings', 'water_method')) {
                $table->string('water_method', 20)->nullable()->after('water_amount');
            }
            if (! Schema::hasColumn('meter_readings', 'electric_photo_path')) {
                $table->string('electric_photo_path')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('meter_readings', 'water_photo_path')) {
                $table->string('water_photo_path')->nullable()->after('electric_photo_path');
            }
            if (! Schema::hasColumn('meter_readings', 'locked_at')) {
                $table->timestamp('locked_at')->nullable()->after('water_photo_path');
            }
        });

        // 3. One reading per room, per tenant, per month
        //    (a tenant who moves out and a new tenant can both have a reading in the same month)
        if (Schema::hasIndex('meter_readings', 'meter_readings_room_id_period_unique')) {
            Schema::table('meter_readings', function (Blueprint $table) {
                $table->unique(['room_id', 'tenant_id', 'period']);
            });
            Schema::table('meter_readings', function (Blueprint $table) {
                $table->dropUnique('meter_readings_room_id_period_unique');
            });
        }

        // 4. Meter replacements
        if (! Schema::hasTable('meter_replacements')) {
            Schema::create('meter_replacements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('room_id')->constrained()->cascadeOnDelete();
                $table->string('utility', 10);                          // electric or water
                $table->char('period', 7);                              // month of the replacement, e.g. 2026-11
                $table->decimal('old_meter_final', 10, 2);              // last reading of the old meter
                $table->decimal('new_meter_start', 10, 2)->default(0);  // first reading of the new meter
                $table->date('replaced_at');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['room_id', 'utility', 'period']);
            });
        }

        // 5. Settings
        $defaults = [
            'electric_min_charge'   => '0',  // riel, 0 = no minimum
            'water_min_charge'      => '0',
            'usage_warning_percent' => '50', // warn when usage changes more than this % from last month
        ];

        foreach ($defaults as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert([
                    'key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        // Method C is now a per-room override, so the global method is always A or B
        DB::table('settings')->where('key', 'electric_tier_mode')->where('value', 'per_room')->update(['value' => 'whole']);
        DB::table('settings')->where('key', 'water_tier_mode')->where('value', 'per_room')->update(['value' => 'progressive']);
    }

    public function down(): void
    {
        Schema::dropIfExists('meter_replacements');

        Schema::table('meter_readings', function (Blueprint $table) {
            $table->dropColumn(['electric_method', 'water_method', 'electric_photo_path', 'water_photo_path', 'locked_at']);
        });

        DB::table('settings')->whereIn('key', ['electric_min_charge', 'water_min_charge', 'usage_warning_percent'])->delete();
    }
};