<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\RecurringExpense;
use App\Models\Setting;
use Carbon\CarbonImmutable;

class ExpenseService
{
    /**
     * Make the monthly expenses (internet, garbage …) from the recurring templates, up to and including $upTo.
     * Each month is made once; a month already there (paid or due) is left as it is.
     */
    public function generateRecurring(string $upTo, string $status = 'due', ?int $rate = null): int
    {
        $rate ??= (int) Setting::getValue('exchange_rate');
        $made = 0;

        foreach (RecurringExpense::all() as $recurring) {
            $month = CarbonImmutable::parse($recurring->starts_on)->startOfMonth();
            $last = $recurring->ends_on ? CarbonImmutable::parse($recurring->ends_on)->format('Y-m') : $upTo;
            $last = min($last, $upTo);
            $existing = $recurring->expenses()->pluck('period')->flip();

            for (; $month->format('Y-m') <= $last; $month = $month->addMonth()) {
                $period = $month->format('Y-m');

                if ($existing->has($period)) {
                    continue;
                }

                $day = min($recurring->day_of_month, $month->daysInMonth);
                Expense::create([
                    'category'             => $recurring->category,
                    'description'          => $recurring->name,
                    'period'               => $period,
                    'spent_on'             => $month->setDay($day)->toDateString(),
                    'amount'               => $recurring->amount,
                    'currency'             => $recurring->currency,
                    'exchange_rate'        => $rate,
                    'amount_riel'          => Expense::toRiel($recurring->amount, $recurring->currency, $rate),
                    'status'               => $status,
                    'paid_on'              => $status === 'paid' ? $month->setDay($day)->toDateString() : null,
                    'method'               => $status === 'paid' ? 'cash' : null,
                    'recurring_expense_id' => $recurring->id,
                    'notes'                => $recurring->payee ? "Paid to {$recurring->payee}" : null,
                ]);
                $made++;
            }
        }

        return $made;
    }
}
