<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An invoice keeps a copy of every number used to calculate it,
     * so later changes to rent, rates or the exchange rate never change an old invoice.
     */
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number', 30)->unique();       // e.g. INV-202611-0001
            $table->foreignId('room_id')->constrained();
            $table->foreignId('tenant_id')->constrained();
            $table->foreignId('meter_reading_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('reading_locked_by_invoice')->default(false);
            $table->char('period', 7);                            // e.g. 2026-11
            $table->date('issue_date');
            $table->date('due_date')->nullable();

            $table->string('room_number', 20);
            $table->string('tenant_name');
            $table->string('tenant_phone', 50)->nullable();

            // Rent (USD), for the days the tenant was in the room
            $table->decimal('rent_full_usd', 10, 2);
            $table->unsignedTinyInteger('occupied_days');
            $table->unsignedTinyInteger('days_in_month');
            $table->decimal('rent_usd', 10, 2);
            $table->unsignedInteger('exchange_rate');             // riel per 1 USD
            $table->unsignedBigInteger('rent_riel');

            foreach (['electric', 'water'] as $utility) {
                $table->decimal("{$utility}_previous", 10, 2)->nullable();
                $table->decimal("{$utility}_current", 10, 2)->nullable();
                $table->decimal("{$utility}_usage", 10, 2)->default(0);
                $table->unsignedInteger("{$utility}_rate")->nullable();
                $table->string("{$utility}_method", 20)->nullable();
                $table->unsignedBigInteger("{$utility}_amount")->default(0);
                $table->string("{$utility}_note")->nullable();    // e.g. meter replaced
            }

            $table->bigInteger('adjustment_riel')->default(0);    // + extra charge, - discount
            $table->string('adjustment_note')->nullable();
            $table->unsignedBigInteger('total_riel');
            $table->unsignedBigInteger('paid_riel')->default(0);
            $table->string('status', 10)->default('unpaid');      // unpaid, partial, paid, void
            $table->text('notes')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason', 500)->nullable();

            // "tenant-room-period" while the invoice is valid, null once voided:
            // one valid invoice per tenant, room and month, and a voided one can be issued again
            $table->string('active_key', 50)->nullable()->unique();
            $table->timestamps();

            $table->index(['period', 'status']);
        });

        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->date('paid_at');
            $table->char('currency', 3);                          // KHR or USD
            $table->decimal('amount_received', 12, 2);            // in the currency received
            $table->unsignedBigInteger('amount_riel');            // counted against the invoice
            $table->string('method', 20);                         // cash, bank, other
            $table->string('reference', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
        Schema::dropIfExists('invoices');
    }
};