<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\MaintenanceRequest;
use App\Models\MeterReading;
use App\Models\PaymentProof;
use App\Models\Tenant;
use App\Models\Setting;
use App\Services\DashboardService;
use App\Services\InvoiceService;
use Carbon\CarbonImmutable;
use App\Services\ExpenseService;
use App\Services\PaymentScoreService;
use Illuminate\Http\Request;

// Owner dashboard
class DashboardController extends Controller
{
    // Meters are read on the same day as EDC reads its meters
    public const READING_DAY = InvoiceService::READING_DAY;

    // How many days after the invoice day a month that is not fully invoiced is still shown as late
    public const LATE_DAYS = 10;

    // GET /api/dashboard/badges   small counts for the menu and the bell, and the box at the bottom of the menu
    public function badges(Request $request)
    {
        $today = CarbonImmutable::today();
        $reading = $today->day > self::READING_DAY ? $today->addMonthNoOverflow()->day(self::READING_DAY) : $today->day(self::READING_DAY);
        $open = Invoice::valid()->whereIn('status', ['unpaid', 'partial']);
        $invoice = $this->nextInvoices($today);

        $counts = [
            'invoices_unpaid'  => (clone $open)->count(),
            'invoices_overdue' => (clone $open)->whereNotNull('due_date')->whereDate('due_date', '<', $today)->count(),
            'proofs_pending'   => PaymentProof::where('status', 'pending')->count(),
            'requests_open'    => MaintenanceRequest::whereIn('status', ['open', 'in_progress'])->count(),
            'edc_to_check'     => Invoice::valid()->whereIn('electric_route', ['direct', 'via_owner'])->where('electric_amount', '>', 0)
                ->where('period', '>=', $today->subMonths(3)->format('Y-m'))
                ->where(fn ($q) => $q->where('edc_status', 'unchecked')->orWhereNull('edc_status'))->count(),
            // Only the owner sees expenses
            'expenses_due'     => $request->user()->role === 'owner'
                ? Expense::where('status', 'due')->where('period', '<=', $today->format('Y-m'))->count() : 0,
        ];

        return response()->json(['data' => [
            'counts'        => $counts,
            'bell'          => $counts['invoices_overdue'] + $counts['proofs_pending'] + $counts['requests_open'] + $counts['edc_to_check'] + $counts['expenses_due']
                + ($invoice['late'] ? 1 : 0),
            'exchange_rate' => (int) Setting::getValue('exchange_rate'),
            'reading'       => [
                'date'      => $reading->toDateString(),
                'days_left' => (int) $today->diffInDays($reading),
                'period'    => $reading->format('Y-m'),
                'done'      => MeterReading::where('period', $reading->format('Y-m'))->count(),
                // Tenants who moved in on or after the reading day have nothing to read yet
                'rooms'     => Tenant::where('status', 'active')->whereNotNull('room_id')
                    ->where(fn ($q) => $q->whereNull('move_in_date')->orWhereDate('move_in_date', '<', $reading))->count(),
            ],
            'invoice'       => $invoice,
        ]]);
    }

    /**
     * The next invoice day (the 30th, or the last day of February) and how many tenants have their invoice.
     * A month that is not fully invoiced stays on the box as late for LATE_DAYS days.
     */
    private function nextInvoices(CarbonImmutable $today): array
    {
        $periods = [$today->startOfMonth()->subMonth()->format('Y-m'), $today->format('Y-m'), $today->startOfMonth()->addMonth()->format('Y-m')];

        foreach ($periods as $period) {
            $date = InvoiceService::invoiceDate($period);
            $done = Invoice::valid()->where('kind', 'monthly')->where('period', $period)->count();
            $tenants = Tenant::where('status', 'active')->whereNotNull('room_id')
                ->where(fn ($q) => $q->whereNull('move_in_date')->orWhereDate('move_in_date', '<=', $date))->count();
            $finished = $done >= $tenants;

            if ($today->gt($date) && ($finished || $today->gt($date->addDays(self::LATE_DAYS)))) {
                continue;
            }

            $late = $today->gt($date);

            return [
                'date'      => $date->toDateString(),
                'period'    => $period,
                'days_left' => $late ? 0 : (int) $today->diffInDays($date),
                'days_late' => $late ? (int) $date->diffInDays($today) : 0,
                'late'      => $late,
                'done'      => $done,
                'tenants'   => $tenants,
            ];
        }

        return [];
    }

    // GET /api/dashboard/overview?period=2026-10   cards and charts for one invoice month (default: the newest)
    public function overview(Request $request, DashboardService $dashboard, ExpenseService $expenses)
    {
        $data = $request->validate(['period' => ['nullable', 'date_format:Y-m']]);
        $period = $data['period'] ?? $dashboard->latestPeriod();

        // Monthly expenses (internet, garbage) are created when a page that counts them is opened
        $expenses->generateRecurring(min($period, now()->format('Y-m')));

        return response()->json([
            'data'    => $dashboard->overview($period),
            'periods' => $dashboard->periods(),
        ]);
    }

    // GET /api/dashboard/payment-scores   how well each tenant pays, from the invoices of the last 6 months
    public function paymentScores(PaymentScoreService $service)
    {
        $rows = $service->forActiveTenants();

        return response()->json([
            'data'    => $rows,
            'summary' => [
                'months'        => PaymentScoreService::MONTHS,
                'tenants'       => $rows->count(),
                'late_payers'   => $rows->filter(fn ($row) => $row['score'] !== null && $row['score'] < 4)->count(),
                'overdue_riel'  => $rows->sum('overdue_riel'),
            ],
        ]);
    }
}
