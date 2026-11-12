<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('nationality', 50)->nullable()->after('gender');
            $table->string('photo_path')->nullable()->after('address');
            $table->decimal('start_electric_meter', 10, 2)->default(0)->after('move_in_date');
            $table->decimal('start_water_meter', 10, 2)->default(0)->after('start_electric_meter');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['nationality', 'photo_path', 'start_electric_meter', 'start_water_meter']);
        });
    }
};