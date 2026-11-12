<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\MaintenanceRequest;
use App\Models\MeterReading;
use App\Models\MeterReplacement;
use App\Models\MoveInPayment;
use App\Models\PaymentProof;
use App\Models\Setting;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    private const UTILITIES = ['electric', 'water'];

    /**
     * An invoice has the water and electricity of $period and the rent of the NEXT month (paid in advance).
     * Lists every tenant who was in a room during either month, including tenants who have moved out,
     * with what would be charged and whether an invoice can be made.
     *
     * status: ready           has a meter reading for $period
     *         rent_only       no utilities to charge: moved in after $period, or moved out without a final reading
     *         missing_reading was in the room during $period, the reading must be saved first
     *         invoiced        already has a valid invoice
     * Tenants with nothing to charge (moved out, no reading, no rent for next month) are left out.
     */
    public function preview(string $period): array
    {
        [$first, $last] = $this->monthBounds($period);
        [$rentFirst, $rentLast] = $this->monthBounds($this->rentPeriod($period));
        $rate = (int) Setting::getValue('exchange_rate');

        $tenants = Tenant::with('room')
            ->whereNotNull('room_id')
            ->where(fn ($query) => $query->whereNull('move_in_date')->orWhereDate('move_in_date', '<=', $rentLast))
            ->where(fn ($query) => $query->whereNull('move_out_date')->orWhereDate('move_out_date', '>=', $first))
            ->get();

        $readings = MeterReading::where('period', $period)->whereIn('tenant_id', $tenants->pluck('id'))->get();
        $invoices = Invoice::valid()->where('kind', 'monthly')->where('period', $period)->whereIn('tenant_id', $tenants->pluck('id'))->get();
        $moveIns = MoveInPayment::whereIn('tenant_id', $tenants->pluck('id'))->get()->keyBy('tenant_id');
        $repairs = MaintenanceRequest::chargeable($tenants->pluck('id')->all())->get();

        return $tenants->map(function (Tenant $tenant) use ($period, $first, $last, $rentFirst, $rentLast, $rate, $readings, $invoices, $moveIns, $repairs) {
            $reading = $readings->first(fn ($item) => $item->tenant_id === $tenant->id && $item->room_id === $tenant->room_id);
            $invoice = $invoices->first(fn ($item) => $item->tenant_id === $tenant->id && $item->room_id === $tenant->room_id);
            $moveIn = $moveIns->get($tenant->id);
            $rent = $this->rent($tenant, $rentFirst, $rentLast, $rate, $moveIn);
            $direct = $tenant->room?->electric_paid_to === 'edc'; // the tenant pays EDC, not the owner
            $utilities = ($direct ? 0 : (int) ($reading?->electric_amount ?? 0)) + (int) ($reading?->water_amount ?? 0);
            $credit = min($this->creditRiel($moveIn, $this->rentPeriod($period), $rate), $rent['rent_riel'] + $utilities);
            $repairRiel = (int) $repairs->where('tenant_id', $tenant->id)->sum(fn ($item) => $this->toRiel((float) $item->cost_usd, $rate));

            return [
                'tenant_id'     => $tenant->id,
                'tenant_name'   => $tenant->full_name,
                'room_id'       => $tenant->room_id,
                'room_number'   => $tenant->room?->room_number,
                'move_in_date'  => $tenant->move_in_date?->toDateString(),
                'move_out_date' => $tenant->move_out_date?->toDateString(),
                'moved_out'     => $tenant->status === 'moved_out',
                'rent_period'   => $this->rentPeriod($period),
                'status'        => $this->previewStatus($tenant, $reading, $invoice, $last, $rent['rent_usd']),
                'occupied_days' => $rent['occupied_days'],
                'days_in_month' => $rent['days_in_month'],
                'rent_usd'      => $rent['rent_usd'],
                'rent_riel'     => $rent['rent_riel'],
                'electric'      => (int) ($reading?->electric_amount ?? 0),
                'electric_direct' => $direct,
                'water'         => (int) ($reading?->water_amount ?? 0),
                'credit_riel'   => $credit,
                'repairs_riel'  => $repairRiel, // repairs the tenant pays for, added to this invoice
                'total_riel'    => $rent['rent_riel'] + $utilities - $credit + $repairRiel,
                'invoice'       => $invoice ? [
                    'id'             => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'status'         => $invoice->status,
                    'total_riel'     => $invoice->total_riel,
                ] : null,
            ];
        })
            ->where('status', '!=', 'nothing')
            ->sortBy([['room_number', 'asc'], ['tenant_id', 'asc']])
            ->values()
            ->all();
    }

    /**
     * Make invoices for the chosen tenants, or for everyone who is ready when none are chosen.
     *
     * @param  int[]|null  $tenantIds
     * @return Invoice[]
     */
    public function generate(string $period, ?array $tenantIds, ?string $issueDate = null, ?string $dueDate = null): array
    {
        $issueDate ??= now()->toDateString();
        $rows = collect($this->preview($period));

        if ($tenantIds !== null) {
            $blocked = $rows->whereIn('tenant_id', $tenantIds)->whereNotIn('status', ['ready', 'rent_only']);

            if ($blocked->isNotEmpty()) {
                $list = $blocked->map(fn ($row) => "{$row['room_number']} {$row['tenant_name']}")->implode(', ');

                throw ValidationException::withMessages([
                    'tenant_ids' => "These tenants cannot be invoiced yet: {$list}.",
                ]);
            }
        }

        $chosen = $rows
            ->whereIn('status', ['ready', 'rent_only'])
            ->when($tenantIds !== null, fn ($items) => $items->whereIn('tenant_id', $tenantIds));

        if ($chosen->isEmpty()) {
            throw ValidationException::withMessages(['period' => "Nothing is ready to invoice for {$period}."]);
        }

        return DB::transaction(function () use ($chosen, $period, $issueDate, $dueDate) {
            $number = $this->lastSequence($period);

            return $chosen->map(function (array $row) use ($period, $issueDate, $dueDate, &$number) {
                return $this->create(Tenant::with('room')->findOrFail($row['tenant_id']), $period, ++$number, $issueDate, $dueDate);
            })->values()->all();
        });
    }

    /**
     * Change the rent, adjustment, dates or notes. Not allowed once money has been received.
     */
    public function update(Invoice $invoice, array $data): Invoice
    {
        if ($invoice->isVoid()) {
            throw ValidationException::withMessages(['invoice' => 'A voided invoice cannot be changed.']);
        }

        $changesMoney = array_key_exists('rent_usd', $data) || array_key_exists('adjustment_riel', $data);

        if ($changesMoney && $invoice->paid_riel > 0) {
            throw ValidationException::withMessages([
                'invoice' => 'This invoice has payments. Remove the payments before changing its amounts.',
            ]);
        }

        $invoice->fill($data);

        if ($changesMoney) {
            $invoice->rent_riel = $this->toRiel((float) $invoice->rent_usd, $invoice->exchange_rate);
            $total = $this->total($invoice);

            if ($total < 0) {
                throw ValidationException::withMessages(['adjustment_riel' => 'The discount cannot be more than the invoice total.']);
            }

            $invoice->total_riel = $total;
            $invoice->status = $this->status($invoice->total_riel, $invoice->paid_riel);
        }

        $invoice->save();

        return $invoice;
    }

    /**
     * Cancel an invoice. Its reading can be edited again and a new invoice can be made.
     */
    public function void(Invoice $invoice, string $reason): Invoice
    {
        if ($invoice->isVoid()) {
            throw ValidationException::withMessages(['invoice' => 'This invoice is already voided.']);
        }

        if ($invoice->payments()->exists()) {
            throw ValidationException::withMessages(['invoice' => 'Remove the payments before voiding this invoice.']);
        }

        return DB::transaction(function () use ($invoice, $reason) {
            if ($invoice->reading_locked_by_invoice && $invoice->meterReading) {
                $invoice->meterReading->update(['locked_at' => null]);
            }

            // The move-in credit can be used on the next invoice instead
            MoveInPayment::where('credit_invoice_id', $invoice->id)->update(['credit_invoice_id' => null]);

            // Repairs on this invoice go on the next one instead
            MaintenanceRequest::whereIn('charged_item_id', $invoice->items()->pluck('id'))->update(['charged_item_id' => null]);

            // A voided repair invoice can be made again from the Requests page
            MaintenanceRequest::where('invoice_id', $invoice->id)->update(['invoice_id' => null]);

            $invoice->update([
                'status'      => 'void',
                'voided_at'   => now(),
                'void_reason' => $reason,
                'active_key'  => null,
            ]);

            return $invoice;
        });
    }

    /**
     * Add another charge: garbage, parking, pest control, materials and repairs...
     */
    public function addItem(Invoice $invoice, array $data): Invoice
    {
        $this->assertItemsCanChange($invoice);

        $quantity = (float) ($data['quantity'] ?? 1);
        $total = $quantity * (float) $data['unit_price'];
        $amount = $data['currency'] === 'USD' ? $this->toRiel($total, $invoice->exchange_rate) : (int) round($total);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['unit_price' => 'The amount must be more than 0.']);
        }

        return DB::transaction(function () use ($invoice, $data, $quantity, $amount) {
            $invoice->items()->create([
                'type'        => $data['type'],
                'description' => $data['description'] ?? null,
                'quantity'    => $quantity,
                'unit_price'  => $data['unit_price'],
                'currency'    => $data['currency'],
                'amount_riel' => $amount,
            ]);

            return $this->syncItems($invoice);
        });
    }

    public function deleteItem(Invoice $invoice, InvoiceItem $item): Invoice
    {
        $this->assertItemsCanChange($invoice);

        return DB::transaction(function () use ($invoice, $item) {
            $item->delete();

            return $this->syncItems($invoice);
        });
    }

    public function addPayment(Invoice $invoice, array $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $data) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);

            if ($invoice->isVoid()) {
                throw ValidationException::withMessages(['invoice' => 'A voided invoice cannot receive payments.']);
            }

            $amount = $data['currency'] === 'USD'
                ? $this->toRiel((float) $data['amount'], $invoice->exchange_rate)
                : (int) round((float) $data['amount']);

            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'The amount must be more than 0.']);
            }

            if ($amount > $invoice->balance()) {
                throw ValidationException::withMessages([
                    'amount' => 'This is more than the balance of ៛' . number_format($invoice->balance()) . '.',
                ]);
            }

            $invoice->payments()->create([
                'paid_at'         => $data['paid_at'],
                'currency'        => $data['currency'],
                'amount_received' => $data['amount'],
                'amount_riel'     => $amount,
                'method'          => $data['method'],
                'reference'       => $data['reference'] ?? null,
                'notes'           => $data['notes'] ?? null,
            ]);

            return $this->syncPaid($invoice);
        });
    }

    public function deletePayment(Invoice $invoice, InvoicePayment $payment): Invoice
    {
        return DB::transaction(function () use ($invoice, $payment) {
            // A KHQR receipt behind this payment goes back to "pending" to be checked again
            PaymentProof::where('payment_id', $payment->id)->update([
                'status' => 'pending', 'payment_id' => null, 'reviewed_by' => null, 'reviewed_at' => null,
            ]);
            $payment->delete();

            return $this->syncPaid($invoice);
        });
    }

    /**
     * A separate invoice for one repair (REP-202611-0001): materials and labor, due today.
     */
    public function createRepairInvoice(MaintenanceRequest $repair): Invoice
    {
        $tenant = $repair->tenant()->with('room')->firstOrFail();
        $rate = (int) Setting::getValue('exchange_rate');
        $period = now()->format('Y-m');

        return DB::transaction(function () use ($repair, $tenant, $rate, $period) {
            $invoice = Invoice::create([
                'invoice_number' => sprintf('REP-%s-%04d', now()->format('Ym'), $this->lastSequence($period, 'REP') + 1),
                'kind'           => 'repair',
                'room_id'        => $repair->room_id,
                'tenant_id'      => $tenant->id,
                'period'         => $period,
                'rent_period'    => null,
                'issue_date'     => now()->toDateString(),
                'due_date'       => now()->toDateString(),
                'room_number'    => $repair->room?->room_number ?? $tenant->room?->room_number ?? '-',
                'tenant_name'    => $tenant->full_name,
                'tenant_phone'   => $tenant->phone,
                'rent_full_usd'  => 0,
                'occupied_days'  => 0,
                'days_in_month'  => 0,
                'rent_usd'       => 0,
                'exchange_rate'  => $rate,
                'rent_riel'      => 0,
                'total_riel'     => 0,
                'paid_riel'      => 0,
                'items_riel'     => 0,
                'credit_riel'    => 0,
                'status'         => 'unpaid',
                'notes'          => "ជួសជុល #{$repair->id}: {$repair->title}",
                'active_key'     => "repair-{$repair->id}",
            ]);

            foreach (['materials_usd' => 'សម្ភារៈ', 'labor_usd' => 'ឈ្នួលជួសជុល'] as $field => $label) {
                if ((float) $repair->{$field} > 0) {
                    $invoice->items()->create([
                        'type'        => 'repair',
                        'description' => "{$label} · ជួសជុល #{$repair->id}: {$repair->title}",
                        'quantity'    => 1,
                        'unit_price'  => $repair->{$field},
                        'currency'    => 'USD',
                        'amount_riel' => $this->toRiel((float) $repair->{$field}, $rate),
                    ]);
                }
            }

            return $this->syncItems($invoice);
        });
    }

    private function create(Tenant $tenant, string $period, int $sequence, string $issueDate, ?string $dueDate): Invoice
    {
        $rentPeriod = $this->rentPeriod($period);
        [$rentFirst, $rentLast] = $this->monthBounds($rentPeriod);
        $exchangeRate = (int) Setting::getValue('exchange_rate');
        $moveIn = MoveInPayment::where('tenant_id', $tenant->id)->first();
        $rent = $this->rent($tenant, $rentFirst, $rentLast, $exchangeRate, $moveIn);

        $reading = MeterReading::where('room_id', $tenant->room_id)
            ->where('tenant_id', $tenant->id)
            ->where('period', $period)
            ->first();
        $direct = $tenant->room->electric_paid_to === 'edc';

        $values = [
            'invoice_number'            => sprintf('INV-%s-%04d', str_replace('-', '', $period), $sequence),
            'room_id'                   => $tenant->room_id,
            'tenant_id'                 => $tenant->id,
            'meter_reading_id'          => $reading?->id,
            'reading_locked_by_invoice' => $reading !== null && ! $reading->isLocked(),
            'period'                    => $period,
            'rent_period'               => $rentPeriod,
            'issue_date'                => $issueDate,
            'due_date'                  => $dueDate,
            'room_number'               => $tenant->room->room_number,
            'tenant_name'               => $tenant->full_name,
            'tenant_phone'              => $tenant->phone,
            'exchange_rate'             => $exchangeRate,
            'active_key'                => "{$tenant->id}-{$tenant->room_id}-{$period}",
            'status'                    => 'unpaid',
            'paid_riel'                 => 0,
            'items_riel'                => 0,
            'electric_route'            => $direct ? 'direct' : 'owner',
            'edc_status'                => $direct ? 'unchecked' : null,
        ] + $rent;

        $replacements = MeterReplacement::where('room_id', $tenant->room_id)
            ->where('tenant_id', $tenant->id)
            ->where('period', $period)
            ->get()
            ->keyBy('utility');

        foreach (self::UTILITIES as $utility) {
            $replacement = $replacements->get($utility);

            $values["{$utility}_previous"] = $reading?->{"{$utility}_previous"};
            $values["{$utility}_current"] = $reading?->{"{$utility}_current"};
            $values["{$utility}_usage"] = $reading?->{"{$utility}_usage"} ?? 0;
            $values["{$utility}_rate"] = $reading?->{"{$utility}_rate"};
            $values["{$utility}_method"] = $reading?->{"{$utility}_method"};
            $values["{$utility}_amount"] = $reading?->{"{$utility}_amount"} ?? 0;
            $values["{$utility}_note"] = $replacement
                ? 'Meter replaced: old meter ended at ' . (float) $replacement->old_meter_final
                    . ', new meter started at ' . (float) $replacement->new_meter_start
                : null;
        }

        $subtotal = $values['rent_riel'] + ($direct ? 0 : $values['electric_amount']) + $values['water_amount'];
        $values['credit_riel'] = min($this->creditRiel($moveIn, $rentPeriod, $exchangeRate), $subtotal);
        $values['total_riel'] = $subtotal - $values['credit_riel'];

        $invoice = Invoice::create($values);
        $this->addRepairCharges($invoice);

        if ($values['credit_riel'] > 0) {
            $moveIn->update(['credit_invoice_id' => $invoice->id]);
        }

        if ($reading && ! $reading->isLocked()) {
            $reading->update(['locked_at' => now()]);
        }

        return $invoice;
    }

    /**
     * Rent for the days the tenant is in the room during the rent month.
     * A full month is the full rent; otherwise rent × days in the room ÷ days in the month.
     */
    private function rent(Tenant $tenant, CarbonImmutable $first, CarbonImmutable $last, int $exchangeRate, ?MoveInPayment $moveIn = null): array
    {
        $start = $tenant->move_in_date && $tenant->move_in_date->gt($first)
            ? CarbonImmutable::parse($tenant->move_in_date)
            : $first;
        $end = $tenant->move_out_date && $tenant->move_out_date->lt($last)
            ? CarbonImmutable::parse($tenant->move_out_date)
            : $last;

        $daysInMonth = $first->daysInMonth;
        $days = max(0, (int) $start->startOfDay()->diffInDays($end->startOfDay()) + 1);
        $full = (float) ($tenant->room?->rent_price ?? 0);
        $rent = $days >= $daysInMonth ? $full : round($full * $days / $daysInMonth, 2);

        // The move-in payment already paid the rent of that month
        if ($moveIn?->covers_period === $first->format('Y-m')) {
            $rent = 0.0;
        }

        return [
            'rent_full_usd' => $full,
            'occupied_days' => $days,
            'days_in_month' => $daysInMonth,
            'rent_usd'      => $rent,
            'rent_riel'     => $this->toRiel($rent, $exchangeRate),
        ];
    }

    private function previewStatus(Tenant $tenant, ?MeterReading $reading, ?Invoice $invoice, CarbonImmutable $utilityMonthEnd, float $rentUsd): string
    {
        if ($invoice) {
            return 'invoiced';
        }

        if ($reading) {
            return 'ready';
        }

        if ($tenant->status === 'moved_out') {
            return $rentUsd > 0 ? 'rent_only' : 'nothing';
        }

        // Moved in after the utility month: only next month's rent can be charged
        $movedInLater = $tenant->move_in_date && $tenant->move_in_date->gt($utilityMonthEnd);

        if (! $movedInLater) {
            return 'missing_reading';
        }

        return $rentUsd > 0 ? 'rent_only' : 'nothing';
    }

    // Done repairs the tenant pays for become lines on the invoice
    private function addRepairCharges(Invoice $invoice): void
    {
        $repairs = MaintenanceRequest::chargeable($invoice->tenant_id)->orderBy('id')->get();

        foreach ($repairs as $repair) {
            $item = $invoice->items()->create([
                'type'        => 'repair',
                'description' => "ជួសជុល #{$repair->id}: {$repair->title}"
                    . ' (សម្ភារៈ $' . number_format((float) $repair->materials_usd, 2)
                    . ' + ឈ្នួល $' . number_format((float) $repair->labor_usd, 2) . ')',
                'quantity'    => 1,
                'unit_price'  => $repair->cost_usd,
                'currency'    => 'USD',
                'amount_riel' => $this->toRiel((float) $repair->cost_usd, $invoice->exchange_rate),
            ]);
            $repair->update(['charged_item_id' => $item->id]);
        }

        if ($repairs->isNotEmpty()) {
            $this->syncItems($invoice);
        }
    }

    private function assertItemsCanChange(Invoice $invoice): void
    {
        if ($invoice->isVoid()) {
            throw ValidationException::withMessages(['invoice' => 'A voided invoice cannot be changed.']);
        }

        if ($invoice->kind === 'repair') {
            throw ValidationException::withMessages([
                'invoice' => 'Change a repair invoice from the Requests page: void it, set a new cost, and it is made again.',
            ]);
        }

        if ($invoice->paid_riel > 0) {
            throw ValidationException::withMessages([
                'invoice' => 'This invoice has payments. Remove the payments before changing its amounts.',
            ]);
        }
    }

    /**
     * EDC rooms: the tenant pays EDC (direct) or gives the money to the owner to pay EDC (via_owner).
     * via_owner also turns an EDC bill the tenant did not pay into a debt on this invoice,
     * which the deposit covers when the tenant leaves.
     */
    public function setElectricRoute(Invoice $invoice, string $route): Invoice
    {
        if ($invoice->isVoid()) {
            throw ValidationException::withMessages(['invoice' => 'A voided invoice cannot be changed.']);
        }

        if ($invoice->electric_route === 'owner') {
            throw ValidationException::withMessages(['electric_route' => 'This room\'s electricity is billed by the owner (sub-meter).']);
        }

        return DB::transaction(function () use ($invoice, $route) {
            $invoice->electric_route = $route;
            $total = $this->total($invoice);

            if ($invoice->paid_riel > $total) {
                throw ValidationException::withMessages(['electric_route' => 'The payments are more than the new total. Remove a payment first.']);
            }

            $invoice->total_riel = $total;
            $invoice->status = $this->status($total, $invoice->paid_riel);
            $invoice->save();

            return $invoice->fresh();
        });
    }

    /**
     * Was this month's EDC bill paid? With the old balance printed on the next EDC bill
     * it is decided for you: 100 riel or less is only rounding, so the bill was paid.
     */
    public function checkEdc(Invoice $invoice, ?string $status, ?int $oldBalance): Invoice
    {
        if ($invoice->electric_route === 'owner') {
            throw ValidationException::withMessages(['edc_status' => 'This room has no EDC bill of its own.']);
        }

        if ($oldBalance !== null) {
            $status = $oldBalance <= 100 ? 'paid' : 'unpaid';
        }

        $invoice->update([
            'edc_status'     => $status,
            'edc_checked_at' => $status === 'unchecked' ? null : now(),
            'edc_note'       => $oldBalance !== null ? 'Next EDC bill: old balance ' . number_format($oldBalance) . ' riel.' : null,
        ]);

        return $invoice;
    }

    private function syncItems(Invoice $invoice): Invoice
    {
        $invoice->items_riel = (int) $invoice->items()->sum('amount_riel');
        $total = $this->total($invoice);

        if ($total < 0) {
            throw ValidationException::withMessages(['invoice' => 'The discount cannot be more than the invoice total.']);
        }

        $invoice->total_riel = $total;
        $invoice->status = $this->status($total, $invoice->paid_riel);
        $invoice->save();

        return $invoice->fresh();
    }

    private function syncPaid(Invoice $invoice): Invoice
    {
        $paid = (int) $invoice->payments()->sum('amount_riel');

        $invoice->update([
            'paid_riel' => $paid,
            'status'    => $this->status($invoice->total_riel, $paid),
        ]);

        return $invoice;
    }

    private function status(int $total, int $paid): string
    {
        return match (true) {
            $paid >= $total => 'paid',
            $paid > 0       => 'partial',
            default         => 'unpaid',
        };
    }

    private function total(Invoice $invoice): int
    {
        return $invoice->rent_riel + $invoice->chargedElectric() + $invoice->water_amount
            + $invoice->items_riel + $invoice->adjustment_riel - $invoice->credit_riel;
    }

    // Prepaid rent left from the move-in payment, for the first rent after the move-in month
    private function creditRiel(?MoveInPayment $moveIn, string $rentPeriod, int $exchangeRate): int
    {
        if (! $moveIn || $moveIn->credit_invoice_id || (float) $moveIn->credit_usd <= 0) {
            return 0;
        }

        return $moveIn->covers_period < $rentPeriod ? $this->toRiel((float) $moveIn->credit_usd, $exchangeRate) : 0;
    }

    // USD → riel, rounded to the nearest 100 riel like the utility charges
    private function toRiel(float $usd, int $exchangeRate): int
    {
        return (int) (round($usd * $exchangeRate / 100) * 100);
    }

    private function lastSequence(string $period, string $type = 'INV'): int
    {
        $prefix = $type . '-' . str_replace('-', '', $period) . '-';

        $last = Invoice::where('invoice_number', 'like', "{$prefix}%")
            ->lockForUpdate()
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        return $last ? (int) substr($last, strlen($prefix)) : 0;
    }

    // "2026-10" → "2026-11"
    private function rentPeriod(string $period): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $period)->addMonthNoOverflow()->format('Y-m');
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function monthBounds(string $period): array
    {
        $first = CarbonImmutable::createFromFormat('!Y-m', $period);

        return [$first, $first->endOfMonth()->startOfDay()];
    }
}
