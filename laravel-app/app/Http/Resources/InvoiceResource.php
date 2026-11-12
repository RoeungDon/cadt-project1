<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $utility = fn (string $key) => [
            'previous' => $this->{"{$key}_previous"} === null ? null : (float) $this->{"{$key}_previous"},
            'current'  => $this->{"{$key}_current"} === null ? null : (float) $this->{"{$key}_current"},
            'usage'    => (float) $this->{"{$key}_usage"},
            'rate'     => $this->{"{$key}_rate"},
            'method'   => $this->{"{$key}_method"},
            'amount'   => $this->{"{$key}_amount"},
            'note'     => $this->{"{$key}_note"},
        ];

        return [
            'id'              => $this->id,
            'invoice_number'  => $this->invoice_number,
            'kind'            => $this->kind ?? 'monthly', // monthly or repair
            'period'          => $this->period,       // month of the water and electricity
            'rent_period'     => $this->rent_period,  // month of the rent (paid in advance)
            'issue_date'      => $this->issue_date?->toDateString(),
            'due_date'        => $this->due_date?->toDateString(),
            'room_id'         => $this->room_id,
            'room_number'     => $this->room_number,
            'tenant_id'       => $this->tenant_id,
            'tenant_name'     => $this->tenant_name,
            'tenant_phone'    => $this->tenant_phone,
            'rent_full_usd'   => (float) $this->rent_full_usd,
            'occupied_days'   => $this->occupied_days,
            'days_in_month'   => $this->days_in_month,
            'rent_usd'        => (float) $this->rent_usd,
            'exchange_rate'   => $this->exchange_rate,
            'rent_riel'       => $this->rent_riel,
            'electric'        => $utility('electric'),
            'water'           => $utility('water'),
            'electric_route'  => $this->electric_route ?? 'owner', // owner, direct (tenant pays EDC) or via_owner
            'electric_charged' => $this->chargedElectric(),       // electricity on the total
            'edc_status'      => $this->edc_status,               // unchecked, paid or unpaid (EDC rooms)
            'edc_checked_at'  => $this->edc_checked_at?->toDateString(),
            'edc_note'        => $this->edc_note,
            // Detail view only (payments loaded): usage more than 50% off the last 3 months
            'usage_alerts'    => $this->when($this->relationLoaded('payments') && $this->meter_reading_id, fn () => \App\Services\UsageAlert::check(
                $this->tenant_id, $this->room_id, $this->period, ['electric' => (float) $this->electric_usage, 'water' => (float) $this->water_usage],
            )),
            'items'           => $this->items->map(fn ($item) => [
                'id'          => $item->id,
                'type'        => $item->type,
                'description' => $item->description,
                'quantity'    => (float) $item->quantity,
                'unit_price'  => (float) $item->unit_price,
                'currency'    => $item->currency,
                'amount_riel' => $item->amount_riel,
            ]),
            'items_riel'      => $this->items_riel,
            'credit_riel'     => $this->credit_riel,  // prepaid rent left over from the move-in payment    
            'adjustment_riel' => $this->adjustment_riel,
            'adjustment_note' => $this->adjustment_note,
            'total_riel'      => $this->total_riel,
            'paid_riel'       => $this->paid_riel,
            'balance_riel'    => $this->balance(),
            'status'          => $this->status,
            'is_overdue'      => $this->isOverdue(),
            'notes'           => $this->notes,
            'voided_at'       => $this->voided_at?->toDateString(),
            'void_reason'     => $this->void_reason,
            'payments'        => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'id'              => $payment->id,
                'paid_at'         => $payment->paid_at->toDateString(),
                'currency'        => $payment->currency,
                'amount_received' => (float) $payment->amount_received,
                'amount_riel'     => $payment->amount_riel,
                'method'          => $payment->method,
                'reference'       => $payment->reference,
                'notes'           => $payment->notes,
            ])),
        ];
    }
}