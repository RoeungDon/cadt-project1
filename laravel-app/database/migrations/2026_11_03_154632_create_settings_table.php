<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        // Default values
        DB::table('settings')->insert([
            ['key' => 'electric_rate', 'value' => '1000', 'created_at' => now(), 'updated_at' => now()], // riel per kWh
            ['key' => 'water_rate', 'value' => '2500', 'created_at' => now(), 'updated_at' => now()],    // riel per m³
            ['key' => 'exchange_rate', 'value' => '4100', 'created_at' => now(), 'updated_at' => now()], // riel per 1 USD
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};