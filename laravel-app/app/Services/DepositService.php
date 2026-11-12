<?php

namespace App\Services;

use App\Models\DepositSettlement;
use App\Models\DepositTransaction;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\MoveInPayment;
use App\Models\MoveOutNotice;
use App\Models\Setting;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DepositService
{
    public const MAX_OVERSTAY_DAYS = 15;

    public function __construct(private InvoiceService $invoiceService)
    {
    }

    /**
     * Everything the deposit screen needs for one tenant.
     */
    public function summary(Tenant $tenant, bool $noticeGiven = true): array
    {
        $tenant->loadMissing('room');
        $moveIn = MoveInPayment::where('tenant_id', $tenant->id)->first();
        $settlement = DepositSettlement::where('tenant_id', $tenant->id)->first();
        $transactions = DepositTransaction::where('tenant_id', $tenant->id)->orderBy('occurred_on')->orderBy('id')->get();

        return [
            'tenant_id'      => $tenant->id,
            'tenant_name'    => $tenant->full_name,
            'status'         => $tenant->status,
            'room_number'    => $tenant->room?->room_number,
            'rent_usd'       => (float) ($tenant->room?->rent_price ?? 0),
            'move_in_date'   => $tenant->move_in_date?->toDateString(),
            'move_out_date'  => $tenant->move_out_date?->toDateString(),
            'balance_usd'    => $this->balance($tenant),
            'move_in'        => $moveIn,
            'settlement'     => $settlement,
            'transactions'   => $transactions,
            'open_invoices'  => $this->openInvoices($tenant)->map(fn (Invoice $invoice) => [
                'id'             => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'balance_riel'   => $invoice->balance(),
            ])->values(),
            'settled_invoices'   => $settlement
                ? InvoicePayment::with('invoice')
                    ->where('method', 'deposit')
                    ->where('reference', "Settlement #{$settlement->id}")
                    ->get()
                    ->map(fn (InvoicePayment $payment) => [
                        'invoice_number' => $payment->invoice?->invoice_number,
                        'amount_riel'    => (int) $payment->amount_riel,
                    ])->values()
                : [],
            'overstay_daily_usd' => $this->dailyRent($tenant),
            'max_overstay_days'  => self::MAX_OVERSTAY_DAYS,
            'unused_rent'        => $this->unusedRent($tenant, $noticeGiven),
            'unused_credit_usd'  => $this->unusedCredit($moveIn),
            'exchange_rate'      => (int) Setting::getValue('exchange_rate'),
            'notice'             => MoveOutNotice::activeFor($tenant->id)?->present(),
        ];
    }

    /**
     * Money received when moving in. Without prepaid rent it can record the deposit of a tenant already in the room.
     */
    public function recordMoveIn(Tenant $tenant, array $data): MoveInPayment
    {
        if (MoveInPayment::where('tenant_id', $tenant->id)->exists()) {
            throw ValidationException::withMessages(['deposit_usd' => 'The move-in payment of this tenant is already recorded.']);
        }

        if (! $tenant->room_id) {
            throw ValidationException::withMessages(['deposit_usd' => 'This tenant has no room.']);
        }

        $tenant->loadMissing('room');
        $prepaid = round((float) ($data['prepaid_usd'] ?? 0), 2);
        $values = ['covers_period' => null, 'covers_days' => 0, 'days_in_month' => 0, 'rent_covered_usd' => 0, 'credit_usd' => 0];

        if ($prepaid > 0) {
            if (! $tenant->move_in_date) {
                throw ValidationException::withMessages(['prepaid_usd' => 'Set the move-in date of this tenant first.']);
            }

            $moveIn = CarbonImmutable::parse($tenant->move_in_date);

            // That month's rent is on an invoice already: it would be paid twice
            if ($this->rentInvoiceExists($tenant->id, $moveIn->format('Y-m'))) {
                throw ValidationException::withMessages(['prepaid_usd' => 'An invoice already charges the rent of ' . $moveIn->format('Y-m') . '. Void that invoice first.']);
            }

            $days = $moveIn->daysInMonth - $moveIn->day + 1;
            $rent = round((float) $tenant->room->rent_price * $days / $moveIn->daysInMonth, 2);
            $covered = min($rent, $prepaid);

            $values = [
                'covers_period'    => $moveIn->format('Y-m'),
                'covers_days'      => $days,
                'days_in_month'    => $moveIn->daysInMonth,
                'rent_covered_usd' => $covered,
                'credit_usd'       => round($prepaid - $covered, 2),
            ];
        }

        return DB::transaction(function () use ($tenant, $data, $prepaid, $values) {
            $payment = MoveInPayment::create($values + [
                'tenant_id'     => $tenant->id,
                'room_id'       => $tenant->room_id,
                'paid_at'       => $data['paid_at'],
                'deposit_usd'   => $data['deposit_usd'],
                'prepaid_usd'   => $prepaid,
                'exchange_rate' => (int) Setting::getValue('exchange_rate'),
                'method'        => $data['method'],
                'reference'     => $data['reference'] ?? null,
                'notes'         => $data['notes'] ?? null,
            ]);

            if ((float) $data['deposit_usd'] > 0) {
                DepositTransaction::create([
                    'tenant_id'          => $tenant->id,
                    'type'               => 'received',
                    'amount_usd'         => $data['deposit_usd'],
                    'occurred_on'        => $data['paid_at'],
                    'description'        => 'Deposit received',
                    'move_in_payment_id' => $payment->id,
                ]);
            }

            return $payment;
        });
    }

    public function deleteMoveIn(MoveInPayment $payment): void
    {
        if ($payment->credit_invoice_id) {
            throw ValidationException::withMessages(['move_in' => 'The credit was used on an invoice. Void that invoice first.']);
        }

        if (DepositSettlement::where('tenant_id', $payment->tenant_id)->exists()) {
            throw ValidationException::withMessages(['move_in' => 'The deposit is already settled. Cancel the settlement first.']);
        }

        // That invoice has no rent because of this payment
        if ($payment->covers_period && $this->rentInvoiceExists($payment->tenant_id, $payment->covers_period)) {
            throw ValidationException::withMessages(['move_in' => 'An invoice relies on this payment for the rent of ' . $payment->covers_period . '. Void that invoice first.']);
        }

        $payment->delete();
    }

    /**
     * Close the deposit of a tenant who has moved out.
     * Charges: unpaid invoices, damage, days stayed too long, other.
     * Returned with the deposit: rent of days not stayed (with notice) and any credit not used.
     *
     * @param  array  $data  overstay_days, notice_given, settled_on, notes,
     *                       deductions: [['type' => 'damage'|'other', 'description' => '...', 'amount_usd' => 25]]
     */
    public function settle(Tenant $tenant, array $data): DepositSettlement
    {
        if ($tenant->status !== 'moved_out') {
            throw ValidationException::withMessages(['tenant' => 'Move this tenant out before settling the deposit.']);
        }

        if (DepositSettlement::where('tenant_id', $tenant->id)->exists()) {
            throw ValidationException::withMessages(['tenant' => 'The deposit of this tenant is already settled.']);
        }

        $days = (int) ($data['overstay_days'] ?? 0);

        if ($days > self::MAX_OVERSTAY_DAYS) {
            throw ValidationException::withMessages(['overstay_days' => 'A tenant can stay at most ' . self::MAX_OVERSTAY_DAYS . ' extra days.']);
        }

        $tenant->loadMissing('room');
        $rate = (int) Setting::getValue('exchange_rate');
        $settledOn = $data['settled_on'] ?? now()->toDateString();
        $notice = (bool) ($data['notice_given'] ?? false);
        $deposit = $this->balance($tenant);
        $moveIn = MoveInPayment::where('tenant_id', $tenant->id)->first();
        $unused = $this->unusedRent($tenant, $notice);
        $unusedCredit = $this->unusedCredit($moveIn);
        $daily = $this->dailyRent($tenant);
        $overstay = round($days * $daily, 2);
        $deductions = collect($data['deductions'] ?? []);
        $damage = round((float) $deductions->where('type', 'damage')->sum('amount_usd'), 2);
        $other = round((float) $deductions->where('type', 'other')->sum('amount_usd'), 2);

        return DB::transaction(function () use (
            $tenant, $data, $rate, $settledOn, $notice, $deposit, $unused, $unusedCredit, $daily, $days, $overstay, $deductions, $damage, $other, $moveIn
        ) {
            $settlement = DepositSettlement::create([
                'tenant_id'          => $tenant->id,
                'room_id'            => $tenant->room_id,
                'settled_on'         => $settledOn,
                'move_out_date'      => $tenant->move_out_date,
                'deposit_usd'        => $deposit,
                'damage_usd'         => $damage,
                'overstay_days'      => $days,
                'overstay_daily_usd' => $daily,
                'overstay_usd'       => $overstay,
                'other_usd'          => $other,
                'notice_given'       => $notice,
                'unused_days'        => $unused['days'],
                'unused_rent_usd'    => $unused['amount_usd'],
                'unused_credit_usd'  => $unusedCredit,
                'exchange_rate'      => $rate,
                'notes'              => $data['notes'] ?? null,
            ]);

            // 1. Unpaid invoices are paid from everything held for the tenant
            $available = round($deposit + $unused['amount_usd'] + $unusedCredit, 2);
            $invoicesUsd = 0.0;

            foreach ($this->openInvoices($tenant) as $invoice) {
                if ($available <= 0) {
                    break;
                }

                $riel = min($invoice->balance(), (int) round($available * $rate));

                if ($riel <= 0) {
                    continue;
                }

                $usd = round($riel / $rate, 2);
                $this->invoiceService->addPayment($invoice, [
                    'paid_at'   => $settledOn,
                    'currency'  => 'KHR',
                    'amount'    => $riel,
                    'method'    => 'deposit',
                    'reference' => "Settlement #{$settlement->id}",
                ]);
                $available = round($available - $usd, 2);
                $invoicesUsd = round($invoicesUsd + $usd, 2);
            }

            // Whatever invoice balance is left is still owed by the tenant
            $leftOnInvoices = round($this->openInvoices($tenant)->sum(fn (Invoice $invoice) => $invoice->balance()) / $rate, 2);

            $net = round($deposit + $unused['amount_usd'] + $unusedCredit - $invoicesUsd - $damage - $overstay - $other, 2);
            $refund = max(0, $net);
            $owed = round(max(0, -$net) + $leftOnInvoices, 2);

            $settlement->update(['invoices_usd' => $invoicesUsd, 'refund_usd' => $refund, 'owed_usd' => $owed]);

            // 2. The deposit's own record
            $log = fn (string $type, float $amount, ?string $description) => $amount > 0
                ? DepositTransaction::create([
                    'tenant_id'     => $tenant->id,
                    'type'          => $type,
                    'amount_usd'    => $amount,
                    'occurred_on'   => $settledOn,
                    'description'   => $description,
                    'settlement_id' => $settlement->id,
                ])
                : null;

            $log('deduct_invoice', min($invoicesUsd, $deposit), 'Unpaid invoices');

            foreach ($deductions as $item) {
                $log($item['type'] === 'damage' ? 'deduct_damage' : 'deduct_other', round((float) $item['amount_usd'], 2), $item['description']);
            }

            $log('deduct_overstay', $overstay, "{$days} extra day(s)");
            $log('refund', max(0, round($this->balance($tenant), 2)), 'Returned to tenant');

            return $settlement;
        });
    }

    /**
     * Undo a settlement made by mistake: its deductions and the invoice payments are removed.
     */
    public function cancelSettlement(DepositSettlement $settlement): void
    {
        DB::transaction(function () use ($settlement) {
            $payments = InvoicePayment::where('method', 'deposit')
                ->where('reference', "Settlement #{$settlement->id}")
                ->get();

            foreach ($payments as $payment) {
                $this->invoiceService->deletePayment($payment->invoice, $payment);
            }

            $settlement->delete();
        });
    }

    public function balance(Tenant $tenant): float
    {
        return round(
            DepositTransaction::where('tenant_id', $tenant->id)->get()->sum(fn ($item) => $item->signedAmount()),
            2,
        );
    }

    private function rentInvoiceExists(int $tenantId, string $rentPeriod): bool
    {
        return Invoice::valid()->where('tenant_id', $tenantId)->where('rent_period', $rentPeriod)->exists();
    }

    private function openInvoices(Tenant $tenant)
    {
        return Invoice::valid()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', ['unpaid', 'partial'])
            ->orderBy('period')
            ->orderBy('id')
            ->get();
    }

    // Rent ÷ days in the month the tenant left (or today's month)
    private function dailyRent(Tenant $tenant): float
    {
        $date = CarbonImmutable::parse($tenant->move_out_date ?? now());

        return round((float) ($tenant->room?->rent_price ?? 0) / $date->daysInMonth, 2);
    }

    /**
     * Rent already paid for the days after moving out. It is returned only with enough notice.
     *
     * @return array{days: int, amount_usd: float}
     */
    private function unusedRent(Tenant $tenant, bool $noticeGiven): array
    {
        $none = ['days' => 0, 'amount_usd' => 0.0];

        if (! $noticeGiven || ! $tenant->move_out_date) {
            return $none;
        }

        $moveOut = CarbonImmutable::parse($tenant->move_out_date);
        $period = $moveOut->format('Y-m');
        $days = $moveOut->daysInMonth - $moveOut->day;

        if ($days <= 0) {
            return $none;
        }

        $invoice = Invoice::valid()
            ->where('tenant_id', $tenant->id)
            ->where('rent_period', $period)
            ->first();
        $moveIn = MoveInPayment::where('tenant_id', $tenant->id)->where('covers_period', $period)->first();

        // Rent charged for this month, by the invoice (paid now or from the deposit) or at move-in
        $charged = max((float) ($invoice?->rent_usd ?? 0), (float) ($moveIn?->rent_covered_usd ?? 0));

        // An invoice made after the move-out only charged the days stayed: nothing to return
        $full = (float) $tenant->room?->rent_price;
        $movedInSameMonth = $tenant->move_in_date && $tenant->move_in_date->format('Y-m') === $period;
        $stayed = $moveOut->day - ($movedInSameMonth ? $tenant->move_in_date->day - 1 : 0);
        $extra = round($charged - $full * $stayed / $moveOut->daysInMonth, 2);

        if ($extra <= 0) {
            return $none;
        }

        $amount = round($full * $days / $moveOut->daysInMonth, 2);

        return ['days' => $days, 'amount_usd' => min($amount, $extra)];
    }

    private function unusedCredit(?MoveInPayment $moveIn): float
    {
        return $moveIn && ! $moveIn->credit_invoice_id ? (float) $moveIn->credit_usd : 0.0;
    }
}