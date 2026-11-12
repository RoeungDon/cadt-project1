<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoicePayment extends Model
{
    public const CURRENCIES = ['KHR', 'USD'];
    public const METHODS = ['cash', 'bank', 'other'];

    protected $fillable = ['invoice_id', 'paid_at', 'currency', 'amount_received', 'amount_riel', 'method', 'reference', 'notes'];

    protected $casts = [
        'paid_at'         => 'date',
        'amount_received' => 'decimal:2',
        'amount_riel'     => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}