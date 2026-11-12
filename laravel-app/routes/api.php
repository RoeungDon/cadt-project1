<?php

use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ContractController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\MainMeterController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\MeterReadingController;
use App\Http\Controllers\PaymentProofController;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\PrivateFileController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoomItemController;
use App\Http\Controllers\RoomUtilityRateController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\TenantController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Login: at most 5 tries a minute from one address
Route::post('login', [AuthController::class, 'login'])->middleware(['throttle:5,1', 'log.activity']);
Route::post('login/qr', [AuthController::class, 'loginWithQr'])->middleware(['throttle:5,1', 'log.activity']);
Route::post('login/two-factor', [AuthController::class, 'loginTwoFactor'])->middleware(['throttle:5,1', 'log.activity']);

// Private documents and photos: only signed links that have not expired open (see App\Support\PrivateFile)
Route::get('files/{path}', PrivateFileController::class)
    ->where('path', '.*')
    ->middleware(['signed:relative', 'throttle:120,1'])
    ->name('private-file');

// Every request checks that the login is still allowed (role middleware), so a removed login stops at once
Route::middleware(['auth:sanctum', 'role:owner,staff,viewer,tenant', 'log.activity'])->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);

    // ---------- Tenant portal: only their own data ----------
    Route::middleware('role:tenant')->prefix('portal')->group(function () {
        Route::get('home', [PortalController::class, 'home']);
        Route::get('invoices', [PortalController::class, 'invoices']);
        Route::get('meter-readings', [PortalController::class, 'meterReadings']);
        Route::get('deposit', [PortalController::class, 'deposit']);
        Route::get('contract', [PortalController::class, 'contract']);
        Route::get('contact', [PortalController::class, 'contact']);
        Route::post('notice', [PortalController::class, 'storeNotice']);
        Route::delete('notice', [PortalController::class, 'cancelNotice']);
        Route::get('repairs', [PortalController::class, 'repairs']);
        Route::post('repairs', [PortalController::class, 'storeRepair']);
        Route::delete('repairs/{maintenanceRequest}', [PortalController::class, 'cancelRepair']);
        Route::post('repairs/{maintenanceRequest}/approve', [PortalController::class, 'approveRepair']);
        Route::post('repairs/{maintenanceRequest}/decline', [PortalController::class, 'declineRepair']);
        Route::get('proofs', [PortalController::class, 'proofs']);
        Route::post('invoices/{invoice}/proofs', [PortalController::class, 'storeProof'])->middleware('throttle:10,1');
        Route::get('proofs/{paymentProof}/image', [PortalController::class, 'proofImage']);
    });

    // ---------- Owner, staff and viewer can read ----------
    Route::middleware('role:owner,staff,viewer')->group(function () {
        Route::put('me/password', [AuthController::class, 'changePassword']);
        Route::get('me/two-factor', [TwoFactorController::class, 'show']);
        Route::post('me/two-factor', [TwoFactorController::class, 'start']);
        Route::post('me/two-factor/confirm', [TwoFactorController::class, 'confirm']);
        Route::post('me/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes']);
        Route::delete('me/two-factor', [TwoFactorController::class, 'destroy']);

        Route::get('rooms', [RoomController::class, 'index']);
        Route::get('rooms/{room}', [RoomController::class, 'show']);
        Route::get('rooms/{room}/items', [RoomItemController::class, 'index']);

        Route::get('tenants', [TenantController::class, 'index']);
        Route::get('tenants/{tenant}', [TenantController::class, 'show']);
        Route::get('tenants/{tenant}/meter-status', [TenantController::class, 'meterStatus']);
        Route::get('tenants/{tenant}/deposit', [DepositController::class, 'show']);
        Route::get('tenants/{tenant}/contract', [ContractController::class, 'show']);

        Route::get('settings', [SettingController::class, 'index']);
        Route::get('dashboard/badges', [DashboardController::class, 'badges']);
        Route::get('room-utility-rates', [RoomUtilityRateController::class, 'index']);
        Route::get('main-meters', [MainMeterController::class, 'index']);
        Route::get('meter-readings', [MeterReadingController::class, 'index']);

        Route::get('invoices/preview', [InvoiceController::class, 'preview']);
        Route::get('invoices/edc-check', [InvoiceController::class, 'edcCheck']);
        Route::get('invoices', [InvoiceController::class, 'index']);
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);

        Route::get('maintenance-requests', [MaintenanceController::class, 'index']);
        Route::get('announcements', [AnnouncementController::class, 'index']);
    });

    // ---------- Owner and staff: daily work ----------
    Route::middleware('role:owner,staff')->group(function () {
        Route::post('rooms', [RoomController::class, 'store']);
        Route::match(['put', 'patch'], 'rooms/{room}', [RoomController::class, 'update']);
        Route::patch('rooms/{room}/status', [RoomController::class, 'updateStatus']);
        Route::put('rooms/{room}/items', [RoomItemController::class, 'update']);
        Route::post('rooms/{room}/items/copy', [RoomItemController::class, 'copy']);
        Route::post('tenants/{tenant}/handover', [ContractController::class, 'saveHandover']);

        Route::post('tenants', [TenantController::class, 'store']);
        Route::match(['put', 'patch'], 'tenants/{tenant}', [TenantController::class, 'update']);
        Route::patch('tenants/{tenant}/move-out', [TenantController::class, 'moveOut']);
        Route::delete('tenants/{tenant}/documents/{document}', [TenantController::class, 'destroyDocument'])
            ->scopeBindings();

        Route::post('meter-readings', [MeterReadingController::class, 'store']);
        Route::post('meter-readings/lock', [MeterReadingController::class, 'lock']);
        Route::post('meter-readings/{meterReading}/photo', [MeterReadingController::class, 'uploadPhoto']);
        Route::post('meter-replacements', [MeterReadingController::class, 'storeReplacement']);
        Route::post('main-meters/{mainMeter}/readings', [MainMeterController::class, 'saveReading']);

        Route::post('invoices/generate', [InvoiceController::class, 'generate']);
        Route::post('invoices/{invoice}/items', [InvoiceController::class, 'storeItem']);
        Route::delete('invoices/{invoice}/items/{item}', [InvoiceController::class, 'destroyItem'])
            ->scopeBindings();
        Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'storePayment']);
        Route::post('invoices/{invoice}/electric-route', [InvoiceController::class, 'electricRoute']);
        Route::post('invoices/{invoice}/edc-status', [InvoiceController::class, 'edcStatus']);

        Route::post('tenants/{tenant}/move-in-payment', [DepositController::class, 'storeMoveIn']);

        Route::post('maintenance-requests', [MaintenanceController::class, 'store']);
        Route::patch('maintenance-requests/{maintenanceRequest}', [MaintenanceController::class, 'update']);
        Route::post('maintenance-requests/{maintenanceRequest}/quote', [MaintenanceController::class, 'quote']);
        Route::post('maintenance-requests/{maintenanceRequest}/approve', [MaintenanceController::class, 'approve']);
        Route::post('maintenance-requests/{maintenanceRequest}/start', [MaintenanceController::class, 'start']);
        Route::post('maintenance-requests/{maintenanceRequest}/complete', [MaintenanceController::class, 'complete']);
        Route::post('maintenance-requests/{maintenanceRequest}/cancel', [MaintenanceController::class, 'cancel']);
        Route::post('maintenance-requests/{maintenanceRequest}/invoice', [MaintenanceController::class, 'issueInvoice']);
        Route::post('announcements', [AnnouncementController::class, 'store']);
        Route::put('announcements/{announcement}', [AnnouncementController::class, 'update']);
        Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy']);

        // ABA KHQR receipts: the pictures show bank details, so viewers do not see them
        Route::get('payment-proofs', [PaymentProofController::class, 'index']);
        Route::get('payment-proofs/{paymentProof}/image', [PaymentProofController::class, 'image']);
        Route::post('payment-proofs/{paymentProof}/confirm', [PaymentProofController::class, 'confirm']);
        Route::post('payment-proofs/{paymentProof}/reject', [PaymentProofController::class, 'reject']);
    });

    // ---------- Owner only: money corrections, deleting, settings ----------
    Route::middleware('role:owner')->group(function () {
        // How well each tenant pays, worked out from the invoices
        Route::get('dashboard/payment-scores', [DashboardController::class, 'paymentScores']);
        // Cards and charts for one invoice month
        Route::get('dashboard/overview', [DashboardController::class, 'overview']);

        Route::delete('rooms/{room}', [RoomController::class, 'destroy']);
        Route::delete('tenants/{tenant}', [TenantController::class, 'destroy']);

        Route::put('settings', [SettingController::class, 'update']);
        Route::post('settings/payment-qr', [SettingController::class, 'uploadPaymentQr']);
        Route::delete('settings/payment-qr', [SettingController::class, 'removePaymentQr']);
        Route::put('room-utility-rates', [RoomUtilityRateController::class, 'update']);
        Route::post('main-meters', [MainMeterController::class, 'store']);
        Route::put('main-meters/{mainMeter}', [MainMeterController::class, 'update']);
        Route::delete('main-meters/{mainMeter}', [MainMeterController::class, 'destroy']);
        Route::delete('main-meter-readings/{mainMeterReading}', [MainMeterController::class, 'destroyReading']);
        Route::get('reports/utility-balance', [MainMeterController::class, 'report']);
        Route::get('reports/profit', [ExpenseController::class, 'report']);

        Route::get('expenses', [ExpenseController::class, 'index']);
        Route::post('expenses', [ExpenseController::class, 'store']);
        Route::put('expenses/{expense}', [ExpenseController::class, 'update']);
        Route::post('expenses/{expense}/pay', [ExpenseController::class, 'pay']);
        Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy']);
        Route::post('recurring-expenses', [ExpenseController::class, 'storeRecurring']);
        Route::put('recurring-expenses/{recurringExpense}', [ExpenseController::class, 'updateRecurring']);
        Route::delete('recurring-expenses/{recurringExpense}', [ExpenseController::class, 'stopRecurring']);

        Route::post('meter-readings/unlock', [MeterReadingController::class, 'unlock']);
        Route::delete('meter-readings/{meterReading}', [MeterReadingController::class, 'destroy']);
        Route::delete('meter-replacements/{meterReplacement}', [MeterReadingController::class, 'destroyReplacement']);

        Route::patch('invoices/{invoice}', [InvoiceController::class, 'update']);
        Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void']);
        Route::delete('invoices/{invoice}/payments/{payment}', [InvoiceController::class, 'destroyPayment'])
            ->scopeBindings();

        Route::delete('move-in-payments/{moveInPayment}', [DepositController::class, 'destroyMoveIn']);
        Route::post('tenants/{tenant}/settlement', [DepositController::class, 'settle']);
        Route::delete('deposit-settlements/{depositSettlement}', [DepositController::class, 'cancelSettlement']);

        // Logins
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::patch('users/{user}', [UserController::class, 'update']);
        Route::post('users/{user}/password', [UserController::class, 'resetPassword']);
        Route::post('users/{user}/two-factor/reset', [TwoFactorController::class, 'reset']);
        Route::post('users/{user}/disable', [UserController::class, 'disable']);
        Route::post('users/{user}/enable', [UserController::class, 'enable']);
        Route::get('tenant-logins', [UserController::class, 'tenantLogins']);
        Route::post('tenants/{tenant}/login-card', [UserController::class, 'issueTenantCard']);
        Route::post('tenants/{tenant}/login/disable', [UserController::class, 'disableTenantLogin']);
    });
});
