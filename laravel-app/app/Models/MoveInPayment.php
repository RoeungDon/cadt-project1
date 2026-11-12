<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MoveInPayment extends Model
{
    public const METHODS = ['cash', 'bank', 'other'];

    protected $guarded = ['id'];

    protected $casts = [
        'paid_at'          => 'date',
        'deposit_usd'      => 'decimal:2',
        'prepaid_usd'      => 'decimal:2',
        'rent_covered_usd' => 'decimal:2',
        'credit_usd'       => 'decimal:2',
        'covers_days'      => 'integer',
        'days_in_month'    => 'integer',
        'exchange_rate'    => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withTrashed();
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class)->withTrashed();
    }
}