<?php

namespace App\Models;

use App\Support\PrivateFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    public const CATEGORIES = ['water', 'electric', 'repair', 'garbage', 'internet', 'supplies', 'other'];
    public const STATUSES = ['paid', 'due'];
    public const METHODS = ['cash', 'bank', 'other'];
    public const CURRENCIES = ['KHR', 'USD'];

    protected $guarded = ['id'];

    protected $casts = [
        'spent_on'      => 'date',
        'paid_on'       => 'date',
        'amount'        => 'float',
        'exchange_rate' => 'integer',
        'amount_riel'   => 'integer',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function mainMeter(): BelongsTo
    {
        return $this->belongsTo(MainMeter::class);
    }

    public function recurring(): BelongsTo
    {
        return $this->belongsTo(RecurringExpense::class, 'recurring_expense_id');
    }

    // Riel at the given rate, rounded to 100 for dollars like the invoices
    public static function toRiel(float $amount, string $currency, int $rate): int
    {
        return $currency === 'USD' ? (int) (round($amount * $rate / 100) * 100) : (int) round($amount);
    }

    public function present(): array
    {
        return [
            'id'             => $this->id,
            'category'       => $this->category,
            'description'    => $this->description,
            'period'         => $this->period,
            'spent_on'       => $this->spent_on?->toDateString(),
            'amount'         => $this->amount,
            'currency'       => $this->currency,
            'exchange_rate'  => $this->exchange_rate,
            'amount_riel'    => $this->amount_riel,
            'status'         => $this->status,
            'paid_on'        => $this->paid_on?->toDateString(),
            'method'         => $this->method,
            'reference'      => $this->reference,
            'room_id'        => $this->room_id,
            'room_number'    => $this->room?->room_number,
            'main_meter_id'  => $this->main_meter_id,
            'main_meter'     => $this->mainMeter?->name,
            'recurring_expense_id' => $this->recurring_expense_id,
            'notes'          => $this->notes,
            'receipt_url'    => PrivateFile::url($this->receipt_path),
        ];
    }
}
