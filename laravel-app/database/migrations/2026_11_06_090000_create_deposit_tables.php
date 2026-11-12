<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deposits belong to the tenant until they are used or returned, so they are kept apart from income.
     */
    public function up(): void
    {
        Schema::create('move_in_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained();
            $table->foreignId('room_id')->constrained();
            $table->date('paid_at');
            $table->decimal('deposit_usd', 10, 2);
            $table->decimal('prepaid_usd', 10, 2)->default(0);       // first rent paid in advance
            $table->char('covers_period', 7)->nullable();            // month of the move-in
            $table->unsignedTinyInteger('covers_days')->default(0);
            $table->unsignedTinyInteger('days_in_month')->default(0);
            $table->decimal('rent_covered_usd', 10, 2)->default(0);  // rent for the days stayed that month
            $table->decimal('credit_usd', 10, 2)->default(0);        // the rest, taken off the next invoice
            $table->foreignId('credit_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->unsignedInteger('exchange_rate');
            $table->string('method', 20);
            $table->string('reference', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('deposit_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained();
            $table->foreignId('room_id')->constrained();
            $table->date('settled_on');
            $table->date('move_out_date')->nullable();
            $table->decimal('deposit_usd', 10, 2);       // deposit held
            $table->decimal('invoices_usd', 10, 2)->default(0);
            $table->decimal('damage_usd', 10, 2)->default(0);
            $table->unsignedTinyInteger('overstay_days')->default(0);
            $table->decimal('overstay_daily_usd', 10, 2)->default(0);
            $table->decimal('overstay_usd', 10, 2)->default(0);
            $table->decimal('other_usd', 10, 2)->default(0);
            $table->boolean('notice_given')->default(false);
            $table->unsignedTinyInteger('unused_days')->default(0);
            $table->decimal('unused_rent_usd', 10, 2)->default(0); // rent returned for days not stayed
            $table->decimal('unused_credit_usd', 10, 2)->default(0);
            $table->decimal('refund_usd', 10, 2)->default(0);      // returned to the tenant
            $table->decimal('owed_usd', 10, 2)->default(0);        // the tenant still owes this
            $table->unsignedInteger('exchange_rate');
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('deposit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained();
            $table->string('type', 20);                 // see DepositTransaction::TYPES
            $table->decimal('amount_usd', 10, 2);       // always positive; the type says in or out
            $table->date('occurred_on');
            $table->string('description')->nullable();
            $table->foreignId('move_in_payment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('settlement_id')->nullable()->constrained('deposit_settlements')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'occurred_on']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('credit_riel')->default(0)->after('items_riel');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('credit_riel');
        });

        Schema::dropIfExists('deposit_transactions');
        Schema::dropIfExists('deposit_settlements');
        Schema::dropIfExists('move_in_payments');
    }
};