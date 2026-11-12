<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\PrivateFile;

class MaintenanceRequest extends Model
{
    public const STATUSES = ['open', 'in_progress', 'done', 'cancelled'];

    public const PRIORITIES = ['normal', 'urgent'];

    public const PAID_BY = ['owner', 'tenant'];

    // prepay: pay before the work · on_completion: pay when it is done · next_invoice: on the next monthly invoice
    public const CHARGE_MODES = ['prepay', 'on_completion', 'next_invoice'];

    protected $guarded = ['id'];

    protected $casts = [
        'materials_usd' => 'decimal:2',
        'labor_usd'     => 'decimal:2',
        'cost_usd'      => 'decimal:2',
        'quoted_at'     => 'datetime',
        'approved_at'   => 'datetime',
        'resolved_on'   => 'date',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withTrashed();
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class)->withTrashed();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    // The tenant pays something for this repair
    public function tenantPays(): bool
    {
        return $this->paid_by === 'tenant' && (float) $this->cost_usd > 0;
    }

    // Already charged: on a repair invoice or a monthly invoice line
    public function isCharged(): bool
    {
        return $this->invoice_id !== null || $this->charged_item_id !== null;
    }

    // Done repairs for the next monthly invoice that are not on an invoice yet
    public function scopeChargeable(Builder $query, int|array $tenantIds): Builder
    {
        return $query->whereIn('tenant_id', (array) $tenantIds)
            ->where('status', 'done')
            ->where('paid_by', 'tenant')
            ->where('charge_mode', 'next_invoice')
            ->where('approval', 'approved')
            ->where('cost_usd', '>', 0)
            ->whereNull('invoice_id')
            ->whereNull('charged_item_id');
    }

    public function present(bool $forStaff = true): array
    {
        $url = fn (?string $path) => PrivateFile::url($path); // signed, expires
        $money = fn ($value) => $value === null ? null : (float) $value;

        return [
            'id'                => $this->id,
            'title'             => $this->title,
            'description'       => $this->description,
            'priority'          => $this->priority,
            'status'            => $this->status,
            'materials_usd'     => $money($this->materials_usd),
            'labor_usd'         => $money($this->labor_usd),
            'cost_usd'          => $money($this->cost_usd),
            'paid_by'           => $this->paid_by,
            'charge_mode'       => $this->charge_mode,
            'quoted_at'         => $this->quoted_at?->toDateString(),
            'approval'          => $this->approval,
            'approved_at'       => $this->approved_at?->toDateString(),
            'approved_by_staff' => $this->approver !== null && ! $this->approver->isTenant(),
            'decline_reason'    => $this->decline_reason,
            'photo_url'         => $url($this->photo_path),
            'after_photo_url'   => $url($this->after_photo_path),
            'receipt_photo_url' => $url($this->receipt_photo_path),
            'resolved_on'       => $this->resolved_on?->toDateString(),
            'staff_note'        => $this->staff_note,
            'charged'           => $this->isCharged(),
            'invoice'           => $this->invoice ? [
                'id'             => $this->invoice->id,
                'invoice_number' => $this->invoice->invoice_number,
                'status'         => $this->invoice->status,
                'total_riel'     => $this->invoice->total_riel,
                'balance_riel'   => $this->invoice->balance(),
            ] : null,
            'created_at'        => $this->created_at?->toDateString(),
        ] + ($forStaff ? [
            'tenant_id'   => $this->tenant_id,
            'tenant_name' => $this->tenant?->full_name,
            'room_number' => $this->room?->room_number,
        ] : []);
    }
}
