<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\RecurringExpense;
use App\Models\Setting;
use App\Services\ExpenseService;
use App\Services\ProfitReportService;
use App\Support\PrivateFile;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// The owner's expenses, monthly costs and the profit and loss report (owner only)
class ExpenseController extends Controller
{
    public function __construct(private ExpenseService $expenses, private ProfitReportService $profit)
    {
    }

    // GET /api/expenses?period=2026-11   also makes this month's internet, garbage … if they are not there yet
    public function index(Request $request)
    {
        $data = $request->validate(['period' => ['nullable', 'date_format:Y-m']]);
        $period = $data['period'] ?? now()->format('Y-m');

        if ($period <= now()->format('Y-m')) {
            $this->expenses->generateRecurring($period);
        }

        $rows = Expense::with(['room', 'mainMeter'])->where('period', $period)->orderBy('spent_on')->orderBy('id')->get();

        return response()->json([
            'period'    => $period,
            'data'      => $rows->map->present(),
            'totals'    => [
                'all'  => (int) $rows->sum('amount_riel'),
                'paid' => (int) $rows->where('status', 'paid')->sum('amount_riel'),
                'due'  => (int) $rows->where('status', 'due')->sum('amount_riel'),
            ],
            'recurring' => RecurringExpense::orderBy('category')->orderBy('name')->get()->map->present(),
        ]);
    }

    // POST /api/expenses
    public function store(Request $request)
    {
        $expense = Expense::create($this->validated($request) + ['created_by' => $request->user()->id]);
        $this->saveReceipt($request, $expense);

        return response()->json(['message' => 'Expense saved.', 'data' => $expense->fresh(['room', 'mainMeter'])->present()], 201);
    }

    // PUT /api/expenses/{expense}   (multipart: send _method=PUT with a receipt)
    public function update(Request $request, Expense $expense)
    {
        $expense->update($this->validated($request, $expense));
        $this->saveReceipt($request, $expense);

        return response()->json(['message' => 'Expense saved.', 'data' => $expense->fresh(['room', 'mainMeter'])->present()]);
    }

    // POST /api/expenses/{expense}/pay   { paid_on, method, reference }
    public function pay(Request $request, Expense $expense)
    {
        $data = $request->validate([
            'paid_on'   => ['required', 'date', 'before_or_equal:today'],
            'method'    => ['required', Rule::in(Expense::METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);
        $expense->update($data + ['status' => 'paid']);

        return response()->json(['message' => 'Marked as paid.', 'data' => $expense->fresh(['room', 'mainMeter'])->present()]);
    }

    // DELETE /api/expenses/{expense}   a monthly one comes back next time the month is opened, unless its template is stopped
    public function destroy(Expense $expense)
    {
        PrivateFile::delete($expense->receipt_path);
        $expense->delete();

        return response()->json(['message' => 'Expense deleted.']);
    }

    // POST /api/recurring-expenses
    public function storeRecurring(Request $request)
    {
        $recurring = RecurringExpense::create($this->validatedRecurring($request));

        return response()->json(['message' => 'Monthly expense added.', 'data' => $recurring->present()], 201);
    }

    // PUT /api/recurring-expenses/{recurringExpense}   changes apply to months not made yet
    public function updateRecurring(Request $request, RecurringExpense $recurringExpense)
    {
        $recurringExpense->update($this->validatedRecurring($request));

        return response()->json(['message' => 'Monthly expense saved.', 'data' => $recurringExpense->present()]);
    }

    // DELETE /api/recurring-expenses/{recurringExpense}   stops it; the months already made stay
    public function stopRecurring(RecurringExpense $recurringExpense)
    {
        if ($recurringExpense->expenses()->exists()) {
            $recurringExpense->update(['ends_on' => now()->endOfMonth()->toDateString()]);

            return response()->json(['message' => 'Stopped after this month.']);
        }

        $recurringExpense->delete();

        return response()->json(['message' => 'Monthly expense deleted.']);
    }

    // GET /api/reports/profit?from=2025-10&to=2026-10
    public function report(Request $request)
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m'],
            'to'   => ['required', 'date_format:Y-m', 'after_or_equal:from'],
        ]);

        if (CarbonImmutable::parse("{$data['from']}-01")->diffInMonths(CarbonImmutable::parse("{$data['to']}-01")) > 36) {
            throw ValidationException::withMessages(['to' => 'Choose at most 36 months.']);
        }

        $this->expenses->generateRecurring(min($data['to'], now()->format('Y-m')));

        return response()->json(['data' => $this->profit->report($data['from'], $data['to'])]);
    }

    private function validated(Request $request, ?Expense $expense = null): array
    {
        $data = $request->validate([
            'category'      => ['required', Rule::in(Expense::CATEGORIES)],
            'description'   => ['required', 'string', 'max:200'],
            'spent_on'      => ['required', 'date'],
            'period'        => ['nullable', 'date_format:Y-m'],
            'amount'        => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'currency'      => ['required', Rule::in(Expense::CURRENCIES)],
            'status'        => ['required', Rule::in(Expense::STATUSES)],
            'paid_on'       => ['nullable', 'required_if:status,paid', 'date', 'before_or_equal:today'],
            'method'        => ['nullable', Rule::in(Expense::METHODS)],
            'reference'     => ['nullable', 'string', 'max:100'],
            'room_id'       => ['nullable', 'exists:rooms,id'],
            'main_meter_id' => ['nullable', 'exists:main_meters,id'],
            'notes'         => ['nullable', 'string', 'max:500'],
            'receipt'       => ['nullable', 'image', 'max:5120'],
        ]);
        unset($data['receipt']);

        $rate = $expense?->exchange_rate ?? (int) Setting::getValue('exchange_rate');

        return [
            'period'        => $data['period'] ?? substr($data['spent_on'], 0, 7),
            'exchange_rate' => $rate,
            'amount_riel'   => Expense::toRiel((float) $data['amount'], $data['currency'], $rate),
            'paid_on'       => $data['status'] === 'paid' ? $data['paid_on'] : null,
        ] + $data;
    }

    private function validatedRecurring(Request $request): array
    {
        return $request->validate([
            'category'     => ['required', Rule::in(Expense::CATEGORIES)],
            'name'         => ['required', 'string', 'max:100'],
            'amount'       => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'currency'     => ['required', Rule::in(Expense::CURRENCIES)],
            'day_of_month' => ['required', 'integer', 'between:1,28'],
            'payee'        => ['nullable', 'string', 'max:100'],
            'starts_on'    => ['required', 'date'],
            'ends_on'      => ['nullable', 'date', 'after_or_equal:starts_on'],
            'notes'        => ['nullable', 'string', 'max:300'],
        ]);
    }

    private function saveReceipt(Request $request, Expense $expense): void
    {
        if ($request->hasFile('receipt')) {
            PrivateFile::delete($expense->receipt_path);
            $expense->update(['receipt_path' => PrivateFile::store($request->file('receipt'), "expenses/{$expense->id}")]);
        }
    }
}
