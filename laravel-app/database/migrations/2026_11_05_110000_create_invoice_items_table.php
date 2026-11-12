<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Other charges on an invoice: garbage, parking, pest control, materials and repairs...
     */
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);                     // see InvoiceItem::TYPES
            $table->string('description')->nullable();      // e.g. "Replace door lock"
            $table->decimal('quantity', 8, 2)->default(1);
            $table->decimal('unit_price', 12, 2);           // in the currency below
            $table->char('currency', 3);                    // KHR or USD
            $table->unsignedBigInteger('amount_riel');      // quantity × unit price, in riel
            $table->timestamps();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('items_riel')->default(0)->after('water_note');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('items_riel');
        });

        Schema::dropIfExists('invoice_items');
    }
};