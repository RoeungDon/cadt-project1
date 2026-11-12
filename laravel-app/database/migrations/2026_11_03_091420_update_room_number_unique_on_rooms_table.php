<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Room number must be unique among active rooms only.
     * Soft-deleted rooms have a deleted_at value, so they no longer block the number.
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropUnique(['room_number']);
            $table->unique(['room_number', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropUnique(['room_number', 'deleted_at']);
            $table->unique('room_number');
        });
    }
};