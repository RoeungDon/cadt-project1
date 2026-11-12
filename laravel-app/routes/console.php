<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/*
 * Create a login, or reset its password:
 *   php artisan rtr:user 012345678 "Owner Name" owner
 * The password is typed in the terminal and is not shown.
 */
Artisan::command('rtr:user {phone} {name} {role=owner}', function (string $phone, string $name, string $role) {
    $phone = User::normalizePhone($phone);
    $password = $this->secret('Password (at least 8 characters, letters and numbers)');
    $confirm = $this->secret('Type the password again');

    $validator = Validator::make(
        ['phone' => $phone, 'role' => $role, 'password' => $password, 'password_confirmation' => $confirm],
        [
            'phone'    => ['required', 'digits_between:8,15'],
            'role'     => ['required', Rule::in(User::STAFF_ROLES)],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ],
    );

    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $message) {
            $this->error($message);
        }

        return 1;
    }

    $user = User::updateOrCreate(
        ['phone' => $phone],
        ['name' => $name, 'role' => $role, 'password' => $password, 'is_active' => true],
    );

    $this->info(($user->wasRecentlyCreated ? 'Created' : 'Updated') . " {$role} login for {$name} ({$phone}).");

    return 0;
})->purpose('Create a login or reset its password');

/*
 * Remove all test data before real use. Keeps the owner login(s) and Settings (water and electricity rates,
 * exchange rate, ABA KHQR). Everything else goes: rooms, tenants, invoices, payments, meter readings, repairs,
 * receipts, deposits, other logins, and the uploaded photos and documents.
 *   php artisan rtr:fresh-start           shows what would be removed (removes nothing)
 *   php artisan rtr:fresh-start --force   removes it, after you type FRESH START
 * Make a database backup first. Refuses to run when APP_ENV=production.
 */
Artisan::command('rtr:fresh-start {--force}', function () {
    if (app()->isProduction()) {
        $this->error('Refused: APP_ENV is production.');

        return 1;
    }

    $tables = array_values(array_filter([
        'payment_proofs', 'invoice_payments', 'invoice_items', 'invoices',
        'deposit_settlements', 'deposit_transactions', 'move_in_payments', 'move_out_notices',
        'maintenance_requests', 'announcements', 'meter_replacements', 'meter_readings', 'main_meter_readings', 'expenses',
        'tenant_documents', 'tenant_occupants', 'tenants', 'rooms',
        'activity_logs', 'sessions', 'personal_access_tokens', 'password_reset_tokens', 'cache', 'cache_locks',
    ], fn (string $table) => Schema::hasTable($table)));

    $folders = ['tenants', 'meter-photos', 'repair-photos', 'payment-proofs', 'expenses'];
    $countFiles = fn (string $disk) => collect($folders)->sum(fn ($folder) => count(Storage::disk($disk)->allFiles($folder)));

    $rows = array_map(fn ($table) => [$table, DB::table($table)->count(), 'remove'], $tables);
    $rows[] = ['users: staff, viewer and tenant logins', User::where('role', '!=', 'owner')->count(), 'remove'];
    $rows[] = ['files: private (photos, documents, receipts)', $countFiles('local'), 'remove'];
    $rows[] = ['files: left in the old public folder', $countFiles('public'), 'remove'];
    $rows[] = ['users: owner', User::where('role', 'owner')->count(), 'KEEP'];
    $rows[] = ['settings (rates, exchange rate, KHQR)', DB::table('settings')->count(), 'KEEP'];
    if (Schema::hasTable('main_meters')) {
        $rows[] = ['main meters (PPWSA, EDC) without their readings', DB::table('main_meters')->count(), 'KEEP'];
    }
    if (Schema::hasTable('recurring_expenses')) {
        $rows[] = ['monthly expenses (internet, garbage …) without their months', DB::table('recurring_expenses')->count(), 'KEEP'];
    }
    $this->table(['What', 'Rows / files', 'Action'], $rows);

    if (! $this->option('force')) {
        $this->info('Nothing was removed. Run again with --force to remove the rows marked "remove".');

        return 0;
    }

    if ($this->ask('This cannot be undone. Type FRESH START to continue') !== 'FRESH START') {
        $this->warn('Cancelled. Nothing was removed.');

        return 1;
    }

    Schema::disableForeignKeyConstraints();

    try {
        DB::table('users')->where('role', '!=', 'owner')->delete();
        DB::table('users')->where('role', 'owner')->update(['tenant_id' => null]);

        foreach ($tables as $table) {
            DB::table($table)->truncate(); // numbering starts again from 1
        }
    } finally {
        Schema::enableForeignKeyConstraints();
    }

    foreach ($folders as $folder) {
        Storage::disk('local')->deleteDirectory($folder);
        Storage::disk('public')->deleteDirectory($folder);
    }

    $this->info('Done. Only the owner login and Settings are left. Log in again (all sessions were closed).');

    return 0;
})->purpose('Remove all test data and keep the owner login and settings');
