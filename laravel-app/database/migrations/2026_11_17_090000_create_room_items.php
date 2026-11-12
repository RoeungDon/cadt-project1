<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What is in each room (bed, fan, air-con…) and how many keys it has.
 * When a tenant moves in, the list is copied onto the tenant (handover_items), so the contract
 * keeps the list from the handover day even if the room's list changes later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('condition', 50)->nullable();
            $table->string('note', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedTinyInteger('key_count')->nullable()->after('description');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->json('handover_items')->nullable();
            $table->unsignedTinyInteger('handover_key_count')->nullable();
            $table->date('handover_saved_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['handover_items', 'handover_key_count', 'handover_saved_on']);
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('key_count');
        });

        Schema::dropIfExists('room_items');
    }
};
