<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rent is paid in advance: an invoice has the utilities of "period" (e.g. 2026-10)
     * and the rent of the following month, "rent_period" (e.g. 2026-11).
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->char('rent_period', 7)->nullable()->after('period');
        });

        DB::table('invoices')->orderBy('id')->get()->each(function ($invoice) {
            DB::table('invoices')->where('id', $invoice->id)->update([
                'rent_period' => date('Y-m', strtotime("{$invoice->period}-01 +1 month")),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('rent_period');
        });
    }
};