<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // monthly = rent and utilities; repair = a repair the tenant pays before or right after the work
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('kind', 10)->default('monthly')->after('invoice_number');
        });

        // Repair requests from tenants (or noted by staff), who pays and how
        Schema::create('maintenance_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_id')->constrained();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('priority', 10)->default('normal');   // normal, urgent
            $table->string('status', 20)->default('open');       // open, in_progress, done, cancelled

            // Cost estimate
            $table->decimal('materials_usd', 10, 2)->nullable();
            $table->decimal('labor_usd', 10, 2)->nullable();
            $table->decimal('cost_usd', 10, 2)->nullable();      // materials + labor
            $table->string('paid_by', 10)->nullable();            // owner, tenant
            $table->string('charge_mode', 15)->nullable();        // prepay, on_completion, next_invoice
            $table->timestamp('quoted_at')->nullable();

            // The tenant agrees before paying: pending, approved, declined (null = nothing to agree to)
            $table->string('approval', 10)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decline_reason', 500)->nullable();

            // Photos: before (tenant), after and the shop receipt (staff)
            $table->string('photo_path')->nullable();
            $table->string('after_photo_path')->nullable();
            $table->string('receipt_photo_path')->nullable();

            $table->date('resolved_on')->nullable();
            $table->string('staff_note', 500)->nullable();

            // How the tenant is charged: a separate repair invoice, or a line on the next monthly invoice
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('charged_item_id')->nullable()->constrained('invoice_items')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        // Notices for all tenants: water or power cuts, price changes...
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->text('body');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('maintenance_requests');

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
