<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    public const STATUSES = ['unpaid', 'partial', 'paid', 'void'];

    protected $guarded = ['id'];

    // owner: income · direct: the tenant pays EDC (not on the total) · via_owner: on the total, passed on to EDC
    public const ELECTRIC_ROUTES = ['owner', 'direct', 'via_owner'];
    public const EDC_STATUSES = ['unchecked', 'paid', 'unpaid'];

    protected $with = ['items'];

    protected $casts = [
        'issue_date'                => 'date',
        'due_date'                  => 'date',
        'voided_at'                 => 'datetime',
        'edc_checked_at'            => 'datetime',
        'reading_locked_by_invoice' => 'boolean',
        'rent_full_usd'             => 'decimal:2',
        'rent_usd'                  => 'decimal:2',
        'electric_previous'         => 'decimal:2',
        'electric_current'          => 'decimal:2',
        'electric_usage'            => 'decimal:2',
        'water_previous'            => 'decimal:2',
        'water_current'             => 'decimal:2',
        'water_usage'               => 'decimal:2',
        'occupied_days'             => 'integer',
        'days_in_month'             => 'integer',
        'exchange_rate'             => 'integer',
        'rent_riel'                 => 'integer',
        'electric_rate'             => 'integer',
        'water_rate'                => 'integer',
        'electric_amount'           => 'integer',
        'water_amount'              => 'integer',
        'items_riel'                => 'integer',
        'credit_riel'               => 'integer',
        'adjustment_riel'           => 'integer',
        'total_riel'                => 'integer',
        'paid_riel'                 => 'integer',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class)->withTrashed();
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withTrashed();
    }

    public function meterReading(): BelongsTo
    {
        return $this->belongsTo(MeterReading::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class)->orderBy('paid_at')->orderBy('id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('id');
    }

    public function scopeValid(Builder $query): Builder
    {
        return $query->whereNotNull('active_key');
    }

    public function balance(): int
    {
        return $this->isVoid() ? 0 : max(0, $this->total_riel - $this->paid_riel);
    }

    public function isVoid(): bool
    {
        return $this->status === 'void';
    }

    public function isOverdue(): bool
    {
        return $this->balance() > 0 && $this->due_date !== null && $this->due_date->lt(today());
    }

    // Electricity the tenant pays to the owner: none when the tenant pays EDC directly
    public function chargedElectric(): int
    {
        return $this->electric_route === 'direct' ? 0 : (int) $this->electric_amount;
    }
}