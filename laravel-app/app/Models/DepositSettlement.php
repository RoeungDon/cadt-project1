<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DepositSettlement extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'settled_on'         => 'date',
        'move_out_date'      => 'date',
        'notice_given'       => 'boolean',
        'deposit_usd'        => 'decimal:2',
        'invoices_usd'       => 'decimal:2',
        'damage_usd'         => 'decimal:2',
        'overstay_daily_usd' => 'decimal:2',
        'overstay_usd'       => 'decimal:2',
        'other_usd'          => 'decimal:2',
        'unused_rent_usd'    => 'decimal:2',
        'unused_credit_usd'  => 'decimal:2',
        'refund_usd'         => 'decimal:2',
        'owed_usd'           => 'decimal:2',
        'overstay_days'      => 'integer',
        'unused_days'        => 'integer',
        'exchange_rate'      => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withTrashed();
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class)->withTrashed();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(DepositTransaction::class, 'settlement_id')->orderBy('id');
    }
}