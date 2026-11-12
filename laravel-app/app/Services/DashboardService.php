<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\MaintenanceRequest;
use App\Models\MoveOutNotice;
use App\Models\PaymentProof;
use App\Models\Room;
use App\Models\Setting;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Everything the owner's dashboard shows for one invoice month, with the 12 months up to it for the charts.
 * Money follows the profit report: tenants' EDC electricity is never income.
 */
class DashboardService
{
    public const TREND_MONTHS = 12;
    public const SPARK_MONTHS = 6;

    public function __construct(private ProfitReportService $profit)
    {
    }

    // The newest invoice month, or this month when there are no invoices yet
    public function latestPeriod(): string
    {
        return Invoice::valid()->max('period') ?? now()->format('Y-m');
    }

    // Invoice months to choose from, newest first
    public function periods(): array
    {
        return Invoice::valid()->select('period')->distinct()->orderByDesc('period')->limit(36)->pluck('period')->all();
    }

    public function overview(string $period): array
    {
        $from = CarbonImmutable::parse("{$period}-01")->subMonths(self::TREND_MONTHS - 1)->format('Y-m');
        $report = $this->profit->report($from, $period);
        $months = $report['months'];
        $now = end($months);
        $before = count($months) > 1 ? $months[count($months) - 2] : null;

        $invoices = Invoice::valid()->with('payments')->where('period', $period)->orderBy('room_number')->get();
        $paidByMonth = $this->paidByMonth(array_column($months, 'period'));

        return [
            'period'        => $period,
            'from'          => $from,
            'exchange_rate' => $report['exchange_rate'],
            'kpis'          => $this->kpis($months, $now, $before, $invoices, $paidByMonth),
            'trend'         => array_map(fn ($m) => [
                'period' => $m['period'], 'income' => $m['income'], 'expenses' => $m['expenses'], 'net' => $m['net'],
            ], $months),
            'income_mix'    => [
                'rent'         => $now['rent'],
                'water'        => $now['water'],
                'electric'     => $now['electric'],
                'other'        => $now['other'],
                'deposit_kept' => $now['deposit_kept'],
            ],
            'not_income'    => ['for_edc' => $now['for_edc'], 'edc_direct' => $now['edc_direct']],
            'collection'    => $this->collection($invoices),
            'rooms'         => $this->rooms($invoices),
            'utilities'     => $this->utilities($invoices, $now),
            'alerts'        => $this->alerts($period, $invoices, $now),
            'recent'        => $this->recent(),
            'room_income'   => collect($report['rooms'])->whereNotNull('room_id')->filter(fn ($r) => $r['income'] > 0)
                ->sortByDesc('income')->values()->map(fn ($r) => ['room_number' => $r['room_number'], 'income' => $r['income'], 'net' => $r['net']])->all(),
        ];
    }

    private function kpis(array $months, array $now, ?array $before, Collection $invoices, array $paidByMonth): array
    {
        $spark = fn (array $values) => array_slice($values, -self::SPARK_MONTHS);
        $change = fn ($a, $b) => ($b === null || (int) $b === 0) ? null : round(($a - $b) / abs($b) * 100, 1);

        $rentable = Room::where('status', '!=', 'owner_use')->count();
        $occupied = Room::where('status', 'occupied')->count();
        $billed = array_map(fn ($m) => $m['period'], $months);
        $roomsBilled = Invoice::valid()->whereIn('period', $billed)->selectRaw('period, count(*) as n')->groupBy('period')->pluck('n', 'period');

        $total = (int) $invoices->sum('total_riel');
        $paid = (int) $invoices->sum('paid_riel');
        $open = Invoice::valid()->whereIn('status', ['unpaid', 'partial'])->get(['total_riel', 'paid_riel', 'due_date']);
        $today = CarbonImmutable::today();

        return [
            'income' => [
                'value' => $now['income'], 'change' => $change($now['income'], $before['income'] ?? null),
                'spark' => $spark(array_column($months, 'income')),
            ],
            'collected' => [
                'value' => $paid, 'of' => $total, 'percent' => $total > 0 ? round($paid / $total * 100) : null,
                'change' => $change($paid, $paidByMonth[$before['period'] ?? ''] ?? null),
                'spark' => $spark(array_values($paidByMonth)),
            ],
            'net' => [
                'value' => $now['net'], 'margin' => $now['income'] > 0 ? round($now['net'] / $now['income'] * 100, 1) : null,
                'change' => $change($now['net'], $before['net'] ?? null),
                'spark' => $spark(array_column($months, 'net')),
            ],
            'occupancy' => [
                'occupied' => $occupied, 'rooms' => $rentable, 'percent' => $rentable > 0 ? round($occupied / $rentable * 100) : null,
                'vacant_days' => $now['vacant_days'], 'lost_rent_riel' => $now['lost_rent_riel'],
                'spark' => $spark(array_map(fn ($p) => (int) ($roomsBilled[$p] ?? 0), $billed)),
            ],
            'tenants' => [
                'value' => Tenant::where('status', 'active')->count(),
                'occupants' => Tenant::where('status', 'active')->withCount('occupants')->get()->sum('occupants_count'),
                'moving_out' => $this->movingOut()->count(),
            ],
            'outstanding' => [
                'value' => (int) $open->sum(fn ($i) => $i->total_riel - $i->paid_riel),
                'invoices' => $open->count(),
                'overdue' => $open->filter(fn ($i) => $i->due_date && $i->due_date->lt($today))->count(),
            ],
        ];
    }

    // Paid on each month's invoices (by invoice month, so it matches income)
    private function paidByMonth(array $periods): array
    {
        $sums = Invoice::valid()->whereIn('period', $periods)->selectRaw('period, sum(paid_riel) as paid')->groupBy('period')->pluck('paid', 'period');

        return collect($periods)->mapWithKeys(fn ($p) => [$p => (int) ($sums[$p] ?? 0)])->all();
    }

    // How this month's invoices were paid: cash, bank (ABA), from the deposit, other
    private function collection(Collection $invoices): array
    {
        $payments = $invoices->flatMap->payments;
        $total = (int) $payments->sum('amount_riel');
        $rows = [];

        foreach (['cash', 'bank', 'deposit', 'other'] as $method) {
            $sum = (int) $payments->where('method', $method)->sum('amount_riel');
            if ($sum > 0 || in_array($method, ['cash', 'bank'], true)) {
                $rows[] = ['method' => $method, 'amount' => $sum, 'count' => $payments->where('method', $method)->count(),
                    'percent' => $total > 0 ? round($sum / $total * 100) : 0];
            }
        }

        return ['methods' => $rows, 'total' => $total, 'unpaid' => (int) $invoices->sum(fn ($i) => max(0, $i->total_riel - $i->paid_riel))];
    }

    // One line per room: this month's invoice, or why there is none
    private function rooms(Collection $invoices): array
    {
        return Room::orderBy('room_number')->get()->map(function (Room $room) use ($invoices) {
            $invoice = $invoices->firstWhere('room_id', $room->id);

            return [
                'room_number' => $room->room_number,
                'room_status' => $room->status,
                'tenant_name' => $invoice?->tenant_name,
                'invoice_id'  => $invoice?->id,
                'total'       => $invoice?->total_riel,
                'paid'        => $invoice?->paid_riel,
                'status'      => $invoice?->status,
                'due_date'    => $invoice?->due_date?->toDateString(),
                'electric_route' => $invoice?->electric_route,
            ];
        })->all();
    }

    private function utilities(Collection $invoices, array $now): array
    {
        return [
            'electric_kwh'      => round((float) $invoices->sum('electric_usage'), 2),
            'electric_owner_kwh' => round((float) $invoices->where('electric_route', 'owner')->sum('electric_usage'), 2),
            'water_m3'          => round((float) $invoices->sum('water_usage'), 2),
            'electric_income'   => $now['electric'],
            'water_income'      => $now['water'],
            'edc_bill'          => $now['by_category']['electric'],
            'ppwsa_bill'        => $now['by_category']['water'],
            'edc_direct'        => $now['edc_direct'],
        ];
    }

    // Things that need the owner: late invoices, EDC bills to check, unusual usage, receipts, repairs, move-outs, bills due.
    // Each has a type and numbers; the page writes the words in the chosen language.
    private function alerts(string $period, Collection $invoices, array $now): array
    {
        $alerts = [];
        $today = CarbonImmutable::today();
        $alert = fn (string $type, string $level, string $icon, string $to, array $data = []) => array_merge(
            ['type' => $type, 'level' => $level, 'icon' => $icon, 'to' => $to, 'amount' => null], $data);

        Invoice::valid()->whereIn('status', ['unpaid', 'partial'])->whereNotNull('due_date')->whereDate('due_date', '<', $today)
            ->orderBy('due_date')->limit(5)->get()->each(function (Invoice $i) use (&$alerts, $today, $alert) {
                $alerts[] = $alert('overdue', 'danger', 'fas fa-file-invoice-dollar', '/invoices', [
                    'room' => $i->room_number, 'period' => $i->period, 'days' => (int) $i->due_date->diffInDays($today), 'amount' => $i->total_riel - $i->paid_riel]);
            });

        $edc = Invoice::valid()->whereIn('electric_route', ['direct', 'via_owner'])->where('electric_amount', '>', 0)
            ->where('period', '<=', $period)->where('period', '>=', CarbonImmutable::parse("{$period}-01")->subMonths(2)->format('Y-m'));
        $unpaid = (clone $edc)->where('edc_status', 'unpaid')->count();
        $unchecked = (clone $edc)->where(fn ($q) => $q->where('edc_status', 'unchecked')->orWhereNull('edc_status'))->count();
        if ($unpaid) {
            $alerts[] = $alert('edc_unpaid', 'danger', 'fas fa-bolt', '/invoices', ['count' => $unpaid]);
        }
        if ($unchecked) {
            $alerts[] = $alert('edc_unchecked', 'warning', 'fas fa-bolt', '/invoices', ['count' => $unchecked]);
        }

        foreach ($invoices as $invoice) {
            $usage = UsageAlert::check($invoice->tenant_id, $invoice->room_id, $period, [
                'electric' => (float) $invoice->electric_usage, 'water' => (float) $invoice->water_usage,
            ]);
            foreach ($usage as $u) {
                $alerts[] = $alert('usage', 'warning', $u['utility'] === 'water' ? 'fas fa-tint' : 'fas fa-plug', '/utilities', [
                    'room' => $invoice->room_number, 'utility' => $u['utility'], 'change' => $u['change_percent']]);
            }
        }

        $proofs = PaymentProof::where('status', 'pending')->count();
        if ($proofs) {
            $alerts[] = $alert('proofs', 'info', 'fas fa-receipt', '/invoices', ['count' => $proofs]);
        }
        $repairs = MaintenanceRequest::whereIn('status', ['open', 'in_progress'])->count();
        if ($repairs) {
            $alerts[] = $alert('repairs', 'info', 'fas fa-tools', '/requests', ['count' => $repairs]);
        }
        $rooms = Room::pluck('room_number', 'id');
        $this->movingOut()->get()->each(function (MoveOutNotice $n) use (&$alerts, $rooms, $alert) {
            $alerts[] = $alert('moving_out', 'info', 'fas fa-sign-out-alt', '/tenants', [
                'room' => $rooms[Tenant::whereKey($n->tenant_id)->value('room_id')] ?? '-', 'date' => $n->planned_move_out_date?->toDateString()]);
        });
        $due = Expense::where('status', 'due')->where('period', '<=', now()->format('Y-m'));
        if ((clone $due)->exists()) {
            $alerts[] = $alert('bills_due', 'warning', 'fas fa-receipt', '/expenses', [
                'count' => (clone $due)->count(), 'amount' => (int) (clone $due)->sum('amount_riel')]);
        }

        return $alerts;
    }

    // Notices of tenants who are still renting
    private function movingOut()
    {
        return MoveOutNotice::active()->whereIn('tenant_id', Tenant::where('status', 'active')->select('id'));
    }

    // Latest money in (payments) and out (paid expenses)
    private function recent(int $limit = 6): array
    {
        $in = InvoicePayment::with('invoice:id,invoice_number,room_number,tenant_name')->orderByDesc('paid_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($p) => ['type' => 'in', 'date' => $p->paid_at?->toDateString(), 'id' => $p->id, 'ref' => $p->invoice?->invoice_number,
                'who' => trim(($p->invoice?->room_number ?? '') . ' · ' . ($p->invoice?->tenant_name ?? ''), ' ·'), 'method' => $p->method, 'amount' => $p->amount_riel]);
        $out = Expense::where('status', 'paid')->orderByDesc('paid_on')->orderByDesc('id')->limit($limit)->get()
            ->map(fn ($e) => ['type' => 'out', 'date' => ($e->paid_on ?? $e->spent_on)?->toDateString(), 'id' => $e->id, 'ref' => $e->category,
                'who' => $e->description, 'method' => $e->method, 'amount' => $e->amount_riel]);

        return $in->concat($out)->sortBy([['date', 'desc'], ['id', 'desc']])->take($limit)->values()->all();
    }
}
