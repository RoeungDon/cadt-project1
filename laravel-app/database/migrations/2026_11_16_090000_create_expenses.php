<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the owner spends: PPWSA and EDC bills, repairs, garbage, internet, supplies.
 * recurring_expenses are templates (internet $15 on the 1st); one "due" expense is made from them each month.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('category', 20);
            $table->string('name', 100);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('KHR');
            $table->unsignedTinyInteger('day_of_month')->default(1); // 1–28
            $table->string('payee', 100)->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();                     // stopped
            $table->string('notes', 300)->nullable();
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('category', 20);
            $table->string('description', 200);
            $table->char('period', 7);                                // month it counts for, e.g. 2026-11
            $table->date('spent_on');                                 // bill date or due date
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('KHR');
            $table->unsignedInteger('exchange_rate');
            $table->unsignedBigInteger('amount_riel');
            $table->string('status', 10)->default('paid');            // paid or due
            $table->date('paid_on')->nullable();
            $table->string('method', 10)->nullable();                 // cash, bank, other
            $table->string('reference', 100)->nullable();             // bill or ABA number
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('main_meter_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('recurring_expense_id')->nullable()->constrained()->nullOnDelete();
            $table->string('receipt_path')->nullable();               // private storage
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('period');
            $table->unique(['recurring_expense_id', 'period']);       // one internet bill a month
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('recurring_expenses');
    }
};
