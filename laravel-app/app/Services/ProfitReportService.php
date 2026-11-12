<?php

namespace App\Services;

use App\Models\DepositSettlement;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Room;
use App\Models\Setting;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Profit and loss by month (an invoice counts in its own month: that month's utilities and next month's rent).
 *
 *   income      rent + water + sub-meter electricity + other items + adjustments (what the owner keeps)
 *               + deposit kept for damage, overstay or other reasons
 *   expenses    PPWSA, the owner's EDC meter, repairs, garbage, internet, supplies, other
 *   net         income − expenses
 * Shown apart, not in the profit:
 *   for EDC     electricity tenants gave the owner to pay EDC (via_owner)
 *   EDC direct  electricity tenants pay EDC themselves, with how many bills are unpaid or not checked
 *   lost rent   days a rentable room had no tenant × its rent today (an estimate)
 */
class ProfitReportService
{
    public function report(string $from, string $to): array
    {
        $periods = $this->periods($from, $to);
        $rate = (int) Setting::getValue('exchange_rate');

        $invoices = Invoice::valid()->whereIn('period', $periods)->get();
        $expenses = Expense::whereIn('period', $periods)->get();
        $settlements = DepositSettlement::whereBetween('settled_on', ["{$from}-01", CarbonImmutable::parse("{$to}-01")->endOfMonth()->toDateString()])->get();
        $vacancy = $this->vacancy($periods, $rate);

        $months = [];
        foreach ($periods as $period) {
            $monthInvoices = $invoices->where('period', $period);
            $monthExpenses = $expenses->where('period', $period);
            $kept = $settlements->filter(fn ($s) => $s->settled_on->format('Y-m') === $period)->sum(fn ($s) => $this->keptRiel($s));
            $months[] = $this->row($period, $monthInvoices, $monthExpenses, $kept, $vacancy['months'][$period]);
        }

        $totals = [];
        foreach (['rent', 'water', 'electric', 'other', 'deposit_kept', 'income', 'expenses', 'expenses_due', 'net', 'for_edc', 'edc_direct', 'edc_unpaid', 'edc_unchecked', 'owed', 'lost_rent_riel', 'vacant_days'] as $key) {
            $totals[$key] = array_sum(array_column($months, $key));
        }
        foreach (Expense::CATEGORIES as $category) {
            $totals['by_category'][$category] = array_sum(array_map(fn ($m) => $m['by_category'][$category], $months));
        }
        $totals['margin_percent'] = $totals['income'] > 0 ? round($totals['net'] / $totals['income'] * 100, 1) : null;

        return [
            'from'          => $from,
            'to'            => $to,
            'exchange_rate' => $rate,
            'months'        => $months,
            'totals'        => $totals,
            'rooms'         => $this->byRoom($invoices, $expenses, $settlements, $vacancy['rooms']),
        ];
    }

    private function row(string $period, Collection $invoices, Collection $expenses, int $kept, array $vacancy): array
    {
        $ownerElectric = (int) $invoices->where('electric_route', 'owner')->sum('electric_amount');
        $rent = (int) $invoices->sum('rent_riel');
        $water = (int) $invoices->sum('water_amount');
        $other = (int) $invoices->sum(fn ($i) => $i->items_riel + $i->adjustment_riel);
        $income = $rent + $water + $ownerElectric + $other + $kept;
        $spent = (int) $expenses->sum('amount_riel');
        $edc = $invoices->whereIn('electric_route', ['direct', 'via_owner'])->where('electric_amount', '>', 0);

        $byCategory = [];
        foreach (Expense::CATEGORIES as $category) {
            $byCategory[$category] = (int) $expenses->where('category', $category)->sum('amount_riel');
        }

        return [
            'period'        => $period,
            'rent'          => $rent,
            'water'         => $water,
            'electric'      => $ownerElectric,
            'other'         => $other,
            'deposit_kept'  => $kept,
            'income'        => $income,
            'expenses'      => $spent,
            'expenses_due'  => (int) $expenses->where('status', 'due')->sum('amount_riel'),
            'by_category'   => $byCategory,
            'net'           => $income - $spent,
            'for_edc'       => (int) $invoices->where('electric_route', 'via_owner')->sum('electric_amount'),
            'edc_direct'    => (int) $invoices->where('electric_route', 'direct')->sum('electric_amount'),
            'edc_unpaid'    => $edc->where('edc_status', 'unpaid')->count(),
            'edc_unchecked' => $edc->filter(fn ($i) => ($i->edc_status ?? 'unchecked') === 'unchecked')->count(),
            'owed'          => (int) $invoices->sum(fn ($i) => max(0, $i->total_riel - $i->paid_riel)),
            'lost_rent_riel' => $vacancy['riel'],
            'vacant_days'   => $vacancy['days'],
        ];
    }

    private function byRoom(Collection $invoices, Collection $expenses, Collection $settlements, array $vacancy): array
    {
        $rows = Room::orderBy('room_number')->get()->map(function (Room $room) use ($invoices, $expenses, $settlements, $vacancy) {
            $mine = $invoices->where('room_id', $room->id);
            $income = (int) $mine->sum(fn ($i) => $i->rent_riel + $i->water_amount + $i->items_riel + $i->adjustment_riel
                + ($i->electric_route === 'owner' ? $i->electric_amount : 0));
            $kept = (int) $settlements->where('room_id', $room->id)->sum(fn ($s) => $this->keptRiel($s));
            $spent = (int) $expenses->where('room_id', $room->id)->sum('amount_riel');

            return [
                'room_id'        => $room->id,
                'room_number'    => $room->room_number,
                'status'         => $room->status,
                'income'         => $income + $kept,
                'expenses'       => $spent,
                'net'            => $income + $kept - $spent,
                'lost_rent_riel' => $vacancy[$room->id]['riel'] ?? 0,
                'vacant_days'    => $vacancy[$room->id]['days'] ?? 0,
            ];
        })->all();

        $shared = (int) $expenses->whereNull('room_id')->sum('amount_riel');
        $rows[] = ['room_id' => null, 'room_number' => 'Shared', 'status' => null, 'income' => 0, 'expenses' => $shared, 'net' => -$shared, 'lost_rent_riel' => 0, 'vacant_days' => 0];

        return $rows;
    }

    /**
     * Days without a tenant for every rentable room (not owner_use), priced at today's rent.
     */
    private function vacancy(array $periods, int $rate): array
    {
        $rooms = Room::where('status', '!=', 'owner_use')->get();
        $stays = Tenant::withTrashed()->get(['room_id', 'move_in_date', 'move_out_date'])->groupBy('room_id');
        $months = array_fill_keys($periods, ['days' => 0, 'riel' => 0]);
        $byRoom = [];
        $today = CarbonImmutable::today();

        foreach ($rooms as $room) {
            foreach ($periods as $period) {
                $start = CarbonImmutable::parse("{$period}-01");
                $days = $start->daysInMonth;
                $empty = 0;

                for ($d = $start; $d->format('Y-m') === $period && $d->lte($today); $d = $d->addDay()) {
                    $taken = ($stays[$room->id] ?? collect())->contains(fn ($t) => $t->move_in_date && $t->move_in_date->lte($d)
                        && (! $t->move_out_date || $t->move_out_date->gte($d)));
                    $empty += $taken ? 0 : 1;
                }

                $riel = (int) (round($empty / $days * (float) $room->rent_price * $rate / 100) * 100);
                $months[$period]['days'] += $empty;
                $months[$period]['riel'] += $riel;
                $byRoom[$room->id]['days'] = ($byRoom[$room->id]['days'] ?? 0) + $empty;
                $byRoom[$room->id]['riel'] = ($byRoom[$room->id]['riel'] ?? 0) + $riel;
            }
        }

        return ['months' => $months, 'rooms' => $byRoom];
    }

    // Money taken from a deposit for damage, overstay or other reasons is the owner's to keep
    private function keptRiel(DepositSettlement $settlement): int
    {
        $usd = (float) $settlement->damage_usd + (float) $settlement->overstay_usd + (float) $settlement->other_usd;

        return (int) (round($usd * $settlement->exchange_rate / 100) * 100);
    }

    private function periods(string $from, string $to): array
    {
        $periods = [];
        for ($m = CarbonImmutable::parse("{$from}-01"); $m->format('Y-m') <= $to; $m = $m->addMonth()) {
            $periods[] = $m->format('Y-m');
        }

        return $periods;
    }
}
