<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner, staff and viewer log in with a phone number and password.
     * A tenant logs in with the QR code on their login card and their phone number.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('phone', 20)->nullable()->unique()->after('name');
            $table->string('role', 20)->default('viewer')->after('phone');
            $table->foreignId('tenant_id')->nullable()->unique()->after('role')->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true)->after('tenant_id');
            $table->timestamp('disabled_at')->nullable()->after('is_active');
            $table->foreignId('disabled_by')->nullable()->after('disabled_at')->constrained('users')->nullOnDelete();
            $table->timestamp('last_login_at')->nullable()->after('disabled_by');

            // Tenant QR login: only a hash is stored, never the QR code itself
            $table->char('qr_token_hash', 64)->nullable()->unique()->after('last_login_at');
            $table->unsignedTinyInteger('qr_failed_attempts')->default(0)->after('qr_token_hash');
            $table->timestamp('qr_locked_at')->nullable()->after('qr_failed_attempts');
        });

        // Who changed what: every change made through the API
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 10);
            $table->string('path');
            $table->unsignedSmallInteger('status');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropForeign(['disabled_by']);
            $table->dropUnique(['phone']);
            $table->dropUnique(['tenant_id']);
            $table->dropUnique(['qr_token_hash']);
            $table->dropColumn([
                'phone', 'role', 'tenant_id', 'is_active', 'disabled_at', 'disabled_by', 'last_login_at',
                'qr_token_hash', 'qr_failed_attempts', 'qr_locked_at',
            ]);
        });
    }
};
