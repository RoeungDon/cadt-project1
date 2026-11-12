<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rooms with their own EDC meter: the tenant pays EDC, not the owner.
 *   rooms.electric_paid_to     owner (sub-meter, the owner's income) or edc (the tenant pays EDC)
 *   invoices.electric_route    owner     electricity is on the total and is the owner's income
 *                              direct    shown for tracking only, not on the total (tenant pays EDC)
 *                              via_owner on the total, the owner passes it on to EDC (not income)
 *   invoices.edc_status        unchecked, paid or unpaid: was this month's EDC bill paid?
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('electric_paid_to', 10)->default('owner')->after('edc_account');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('electric_route', 10)->default('owner')->after('electric_note');
            $table->string('edc_status', 10)->nullable()->after('electric_route');
            $table->timestamp('edc_checked_at')->nullable()->after('edc_status');
            $table->string('edc_note', 200)->nullable()->after('edc_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['electric_route', 'edc_status', 'edc_checked_at', 'edc_note']);
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('electric_paid_to');
        });
    }
};
