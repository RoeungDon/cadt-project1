<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\MainMeter;
use App\Models\RecurringExpense;
use App\Models\Room;
use App\Services\ExpenseService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Import past expenses (PPWSA bills, the owner's EDC bills, repairs) and the monthly ones (internet, garbage).
 * The monthly ones are made for every month up to "recurring_paid_until" and marked paid.
 *
 *   php artisan rtr:import-expenses           checks the file, saves nothing
 *   php artisan rtr:import-expenses --force   saves it, after you type IMPORT
 */
class ImportExpenses extends Command
{
    protected $signature = 'rtr:import-expenses {--file= : JSON file, default storage/app/private/import/rtr-expenses.json} {--force : Save the import}';

    protected $description = 'Import past expenses and the monthly expenses from a JSON file';

    public function handle(ExpenseService $service): int
    {
        $path = $this->option('file') ?: storage_path('app/private/import/rtr-expenses.json');
        $data = is_file($path) ? json_decode(file_get_contents($path), true) : null;

        if (! is_array($data) || ($data['version'] ?? null) !== 1) {
            $this->error("Not an RTRroom expenses file (version 1): {$path}");

            return 1;
        }

        if (Expense::exists() || RecurringExpense::exists()) {
            $this->error('There are expenses already. Import only once, before entering expenses by hand.');

            return 1;
        }

        $rooms = Room::pluck('id', 'room_number');
        $meters = MainMeter::pluck('id', 'account');
        $errors = [];

        foreach ($data['expenses'] as $i => $row) {
            if (! in_array($row['category'], Expense::CATEGORIES, true)) {
                $errors[] = "Row {$i}: unknown category {$row['category']}.";
            }
            if (! empty($row['room']) && ! $rooms->has($row['room'])) {
                $errors[] = "Row {$i}: room {$row['room']} is not in the system (run rtr:import first).";
            }
            if (! empty($row['main_meter_account']) && ! $meters->has($row['main_meter_account'])) {
                $errors[] = "Row {$i}: main meter {$row['main_meter_account']} is not in the system.";
            }
        }

        if ($errors) {
            array_map(fn ($e) => $this->error($e), $errors);
            $this->error('Nothing was saved.');

            return 1;
        }

        DB::beginTransaction();

        try {
            foreach ($data['recurring'] as $row) {
                RecurringExpense::create($row);
            }
            $service->generateRecurring($data['recurring_paid_until'], 'paid', (int) $data['history_rate']);

            foreach ($data['expenses'] as $row) {
                Expense::create([
                    'category'      => $row['category'],
                    'description'   => $row['description'],
                    'period'        => $row['period'],
                    'spent_on'      => $row['spent_on'],
                    'amount'        => $row['amount'],
                    'currency'      => $row['currency'],
                    'exchange_rate' => (int) $data['history_rate'],
                    'amount_riel'   => Expense::toRiel((float) $row['amount'], $row['currency'], (int) $data['history_rate']),
                    'status'        => 'paid',
                    'paid_on'       => $row['paid_on'] ?? $row['spent_on'],
                    'method'        => $row['method'] ?? 'cash',
                    'reference'     => $row['reference'] ?? null,
                    'room_id'       => empty($row['room']) ? null : $rooms[$row['room']],
                    'main_meter_id' => empty($row['main_meter_account']) ? null : $meters[$row['main_meter_account']],
                    'notes'         => $row['notes'] ?? null,
                ]);
            }

            $actual = ['expenses' => Expense::count(), 'amount_riel' => (int) Expense::sum('amount_riel')];
            foreach (Expense::CATEGORIES as $category) {
                $actual[$category] = (int) Expense::where('category', $category)->sum('amount_riel');
            }

            $ok = true;
            $rows = [];
            foreach ($data['checks'] as $key => $value) {
                $same = ($actual[$key] ?? null) === $value;
                $ok = $ok && $same;
                $rows[] = [$key, number_format($value), number_format($actual[$key] ?? 0), $same ? 'OK' : 'DIFFERENT'];
            }
            $this->table(['What', 'File', 'Database', ''], $rows);
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Import failed, nothing was saved: ' . $e->getMessage());

            return 1;
        }

        if (! $ok) {
            DB::rollBack();
            $this->error('The totals do not match the file. Nothing was saved.');

            return 1;
        }

        if (! $this->option('force')) {
            DB::rollBack();
            $this->info('Check passed. Nothing was saved. Run again with --force to save.');

            return 0;
        }

        if ($this->ask('Type IMPORT to save') !== 'IMPORT') {
            DB::rollBack();
            $this->warn('Cancelled. Nothing was saved.');

            return 1;
        }

        DB::commit();
        $this->info('Saved. Open Expenses and Reports to look through it.');

        return 0;
    }
}
