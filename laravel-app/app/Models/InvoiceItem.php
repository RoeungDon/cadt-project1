<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    public const TYPES = [
        'garbage',           // ថ្លៃសម្រាម
        'parking_motorbike', // ថ្លៃចំណតម៉ូតូ
        'parking_car',       // ថ្លៃចំណតឡាន
        'parking_tricycle',  // ថ្លៃចំណតកង់បី
        'pest_control',      // ថ្លៃកំចាត់សត្វល្អិត
        'repair',            // ថ្លៃសម្ភារៈ និងជួសជុល
        'other',             // ផ្សេងៗ
    ];

    protected $fillable = ['invoice_id', 'type', 'description', 'quantity', 'unit_price', 'currency', 'amount_riel'];

    protected $casts = [
        'quantity'    => 'decimal:2',
        'unit_price'  => 'decimal:2',
        'amount_riel' => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}