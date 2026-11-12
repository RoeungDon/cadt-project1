<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// A monthly cost such as internet or garbage: one "due" expense is made from it each month
class RecurringExpense extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'amount'       => 'float',
        'day_of_month' => 'integer',
        'starts_on'    => 'date',
        'ends_on'      => 'date',
    ];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function present(): array
    {
        return [
            'id'           => $this->id,
            'category'     => $this->category,
            'name'         => $this->name,
            'amount'       => $this->amount,
            'currency'     => $this->currency,
            'day_of_month' => $this->day_of_month,
            'payee'        => $this->payee,
            'starts_on'    => $this->starts_on->toDateString(),
            'ends_on'      => $this->ends_on?->toDateString(),
            'notes'        => $this->notes,
            'active'       => $this->ends_on === null || $this->ends_on->isFuture(),
        ];
    }
}
