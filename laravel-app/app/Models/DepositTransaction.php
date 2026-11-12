<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepositTransaction extends Model
{
    public const TYPES = ['received', 'deduct_invoice', 'deduct_damage', 'deduct_overstay', 'deduct_other', 'refund'];

    protected $guarded = ['id'];

    protected $casts = [
        'amount_usd'  => 'decimal:2',
        'occurred_on' => 'date',
    ];

    // Money coming in is positive, everything else takes from the deposit
    public function signedAmount(): float
    {
        return $this->type === 'received' ? (float) $this->amount_usd : -(float) $this->amount_usd;
    }
}