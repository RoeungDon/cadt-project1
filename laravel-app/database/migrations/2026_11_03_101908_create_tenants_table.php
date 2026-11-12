<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();

            // Basic information
            $table->string('full_name', 100);
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->string('phone', 20);
            $table->string('email', 100)->nullable();
            $table->string('id_card_number', 50)->nullable();
            $table->string('address')->nullable();

            // Emergency contact
            $table->string('emergency_contact_name', 100)->nullable();
            $table->string('emergency_contact_phone', 20)->nullable();

            // Stay information
            $table->foreignId('room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->date('move_in_date')->nullable();
            $table->date('move_out_date')->nullable();
            $table->enum('status', ['active', 'moved_out'])->default('active');

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};