<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * How well each tenant pays, worked out from the invoices only (no manual rating).
 * Each invoice gets 1 to 5 by how many days late it was paid; the tenant's score is the average.
 */
class PaymentScoreService
{
    public const MONTHS = 6;

    // Days late → score. On time = 5, 1–3 = 4, 4–7 = 3, 8–15 = 2, more or still unpaid long after = 1
    private const LATE_STEPS = [0 => 5, 3 => 4, 7 => 3, 15 => 2];

    /**
     * Every tenant renting now, worst payers first, from the invoices of the last 6 months.
     */
    public function forActiveTenants(): Collection
    {
        $today = CarbonImmutable::today();
        $since = $today->subMonthsNoOverflow(self::MONTHS - 1)->format('Y-m');

        $tenants = Tenant::with('room')->where('status', 'active')->whereNotNull('room_id')->get();

        $invoices = Invoice::valid()
            ->with('payments')
            ->whereIn('tenant_id', $tenants->pluck('id'))
            ->whereNotNull('due_date')
            ->where('period', '>=', $since)
            ->get()
            ->groupBy('tenant_id');

        return $tenants
            ->map(fn (Tenant $tenant) => $this->row($tenant, $invoices->get($tenant->id, collect()), $today))
            // Lowest score first; tenants with nothing to judge yet at the end
            ->sortBy(fn (array $row) => [$row['score'] ?? 9, $row['room_number']])
            ->values();
    }

    public function scoreForDaysLate(int $days): int
    {
        foreach (self::LATE_STEPS as $limit => $score) {
            if ($days <= $limit) {
                return $score;
            }
        }

        return 1;
    }

    /**
     * Days late for one invoice: paid on the date the last payment cleared it, or still unpaid today.
     * Null when it is unpaid but not due yet.
     */
    public function daysLate(Invoice $invoice, CarbonImmutable $today): ?int
    {
        $paidOn = $invoice->balance() === 0 ? $invoice->payments->max('paid_at') : null;

        if (! $paidOn && $invoice->due_date->gte($today)) {
            return null;
        }

        $until = $paidOn ? CarbonImmutable::parse($paidOn) : $today;

        return max(0, (int) $invoice->due_date->diffInDays($until, false));
    }

    private function row(Tenant $tenant, Collection $invoices, CarbonImmutable $today): array
    {
        $late = $invoices->map(fn (Invoice $invoice) => $this->daysLate($invoice, $today))->filter(fn ($days) => $days !== null);
        $overdue = $invoices->filter(fn (Invoice $invoice) => $invoice->balance() > 0 && $invoice->due_date->lt($today));

        return [
            'tenant_id'      => $tenant->id,
            'tenant_name'    => $tenant->full_name,
            'room_number'    => $tenant->room?->room_number,
            'phone'          => $tenant->phone,
            'score'          => $late->isEmpty() ? null : round($late->avg(fn (int $days) => $this->scoreForDaysLate($days)), 1),
            'invoices'       => $late->count(),
            'late_count'     => $late->filter(fn (int $days) => $days > 0)->count(),
            'max_days_late'  => (int) $late->max(),
            'overdue_count'  => $overdue->count(),
            'overdue_riel'   => (int) $overdue->sum(fn (Invoice $invoice) => $invoice->balance()),
        ];
    }
}
