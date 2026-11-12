<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A tenant paid by ABA KHQR and sent the receipt; owner or staff checks it in ABA and confirms
        Schema::create('payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('currency', 3);                         // KHR or USD
            $table->decimal('amount', 12, 2);                      // what the tenant says they paid
            $table->date('paid_on');
            $table->string('transaction_ref', 60)->nullable();     // ABA transaction ID, optional
            $table->string('image_path');                          // private disk: receipts show bank details
            $table->char('image_hash', 64);                        // SHA-256 of the file: the same picture cannot be sent twice
            $table->string('status', 10)->default('pending');      // pending, confirmed, rejected
            $table->string('reject_reason', 500)->nullable();
            $table->foreignId('payment_id')->nullable()->constrained('invoice_payments')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('image_hash');
            $table->index('transaction_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_proofs');
    }
};
