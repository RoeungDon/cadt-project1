<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A tenant tells the owner when they will leave. The date it was given decides
     * whether the 30 days' notice for the deposit was met.
     */
    public function up(): void
    {
        Schema::create('move_out_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->date('given_on');
            $table->date('planned_move_out_date');
            $table->string('reason', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'cancelled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('move_out_notices');
    }
};
