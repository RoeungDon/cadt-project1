<?php

namespace App\Console\Commands;

use App\Models\DepositSettlement;
use App\Models\DepositTransaction;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\MainMeter;
use App\Models\MainMeterReading;
use App\Models\MeterReading;
use App\Models\MoveInPayment;
use App\Models\Room;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\UtilityTariff;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Import the history kept on paper (rooms, tenants, deposits, readings, invoices, payments) from a JSON file.
 * Everything before the import is an opening balance: every invoice in the file is recorded as paid.
 *
 *   php artisan rtr:import           checks the file and shows what would be saved (saves nothing)
 *   php artisan rtr:import --force   saves it, after you type IMPORT
 *
 * The file holds tenants' names and phone numbers, so it lives in storage/app/private (not in git).
 * Runs only on an empty database: no rooms, tenants, readings or invoices yet.
 */
class ImportHistory extends Command
{
    protected $signature = 'rtr:import {--file= : JSON file, default storage/app/private/import/rtr-history.json} {--force : Save the import}';

    protected $description = 'Import the paper records (opening balance) from a JSON file';

    private array $errors = [];
    private array $warnings = [];
    private array $settings = [];

    public function handle(): int
    {
        $path = $this->option('file') ?: storage_path('app/private/import/rtr-history.json');

        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return 1;
        }

        $data = json_decode(file_get_contents($path), true);

        if (! is_array($data) || ($data['version'] ?? null) !== 1) {
            $this->error('The file is not an RTRroom import file (version 1).');

            return 1;
        }

        $existing = [
            'rooms'          => Room::withTrashed()->count(),
            'tenants'        => Tenant::withTrashed()->count(),
            'meter readings' => MeterReading::count(),
            'invoices'       => Invoice::count(),
        ];

        if (array_sum($existing) > 0) {
            $this->error('The database already has data: ' . collect($existing)->filter()->map(fn ($n, $what) => "{$n} {$what}")->implode(', ') . '.');
            $this->line('Import only into an empty database (php artisan rtr:fresh-start --force first).');

            return 1;
        }

        $this->settings = Setting::allValues();
        $this->check($data);

        foreach ($this->warnings as $warning) {
            $this->warn("Note: {$warning}");
        }

        if ($this->errors) {
            foreach ($this->errors as $error) {
                $this->error($error);
            }
            $this->error(count($this->errors) . ' problem(s) found. Nothing was saved.');

            return 1;
        }

        DB::beginTransaction();

        try {
            Model::unguarded(fn () => $this->import($data));
            $ok = $this->compare($data['checks']);
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
        $this->info('Saved. Open Rooms, Tenants, Invoices and Reports to look through it.');

        return 0;
    }

    /**
     * Checks before anything is written: each month continues from the last one, and the sums add up.
     */
    private function check(array $data): void
    {
        $rooms = collect($data['rooms'])->keyBy('room_number');
        $tenants = collect($data['tenants'])->keyBy('key');
        $last = [];

        foreach ($data['tenants'] as $tenant) {
            if (! $rooms->has($tenant['room'])) {
                $this->errors[] = "{$tenant['key']}: unknown room {$tenant['room']}.";
            }
            $last[$tenant['key']] = ['electric' => (float) $tenant['start_electric_meter'], 'water' => (float) $tenant['start_water_meter']];
        }

        $months = collect($data['months'])->sortBy('period');

        foreach ($months as $month) {
            $where = "{$month['room']} {$month['period']} ({$month['tenant']})";
            $tenant = $tenants->get($month['tenant']);

            if (! $tenant || $tenant['room'] !== $month['room']) {
                $this->errors[] = "{$where}: the tenant is not in this room.";
                continue;
            }

            foreach (['electric', 'water'] as $utility) {
                $u = $month[$utility] ?? null;

                if ($u === null) {
                    continue;
                }

                if (abs($u['previous'] - $last[$month['tenant']][$utility]) > 0.001) {
                    $this->errors[] = "{$where}: {$utility} starts at {$u['previous']}, but the month before ended at {$last[$month['tenant']][$utility]}.";
                }

                if (abs(($u['current'] - $u['previous']) - $u['usage']) > 0.001 || $u['usage'] < 0) {
                    $this->errors[] = "{$where}: {$utility} usage {$u['usage']} is not {$u['current']} − {$u['previous']}.";
                }

                if (abs($u['usage'] * $u['rate'] - $u['amount']) > 1) {
                    $this->errors[] = "{$where}: {$utility} {$u['usage']} × {$u['rate']} is not {$u['amount']}.";
                }

                // What the system would charge today, only as a note
                if ($u['method'] === 'whole') {
                    $system = UtilityTariff::calculate((float) $u['usage'], $this->settings["{$utility}_tiers"], 'whole');

                    if (abs($system['amount'] - $u['amount']) > 100) {
                        $this->warnings[] = "{$where}: {$utility} charged " . number_format($u['amount']) . ', the tiers in Settings give ' . number_format($system['amount']) . ' (kept as charged).';
                    }
                }

                $last[$month['tenant']][$utility] = (float) $u['current'];
            }

            $total = $this->rentRiel($month) + $this->chargedElectric($month) + ($month['water']['amount'] ?? 0)
                + collect($month['items'])->sum('amount_riel');

            if ($total !== (int) $month['total_riel']) {
                $this->errors[] = "{$where}: the parts add up to {$total}, not {$month['total_riel']}.";
            }

            $paid = collect($month['payments'])->sum(fn ($p) => $this->paymentRiel($p, $month));

            if (! ($month['paid_by_deposit'] ?? false) && $paid !== (int) $month['total_riel']) {
                $this->errors[] = "{$where}: payments add up to {$paid}, not {$month['total_riel']}.";
            }
        }

        foreach ($data['tenants'] as $tenant) {
            if ($tenant['status'] === 'active' && $tenant['move_out_date']) {
                $this->errors[] = "{$tenant['key']}: an active tenant cannot have a move-out date.";
            }
        }
    }

    private function import(array $data): void
    {
        $meters = [];

        foreach ($data['main_meters'] as $row) {
            $meter = MainMeter::where('utility', $row['utility'])->where('account', $row['account'])->first()
                ?? MainMeter::create(collect($row)->only(['utility', 'name', 'account', 'tier_mode', 'extra_percent', 'monthly_fee'])->all());

            if ($row['readings'] && $meter->readings()->exists()) {
                throw new \RuntimeException("Main meter {$row['account']} already has readings.");
            }

            foreach ($row['readings'] as $reading) {
                MainMeterReading::create($reading + ['main_meter_id' => $meter->id]);
            }

            $meters[$row['account']] = $meter->id;
        }

        $rooms = [];

        foreach ($data['rooms'] as $row) {
            $rooms[$row['room_number']] = Room::create([
                'room_number'            => $row['room_number'],
                'floor'                  => $row['floor'],
                'rent_price'             => $row['rent_price'],
                'status'                 => $row['status'],
                'electric_rate_type'     => $row['electric'][0] ?? null,
                'electric_custom_rate'   => $row['electric'][1] ?? null,
                'water_rate_type'        => $row['water'][0] ?? null,
                'water_custom_rate'      => $row['water'][1] ?? null,
                'edc_account'            => $row['edc_account'],
                'electric_paid_to'       => $row['electric_paid_to'] ?? 'owner',
                'electric_main_meter_id' => $row['electric_main'] ? $meters[$row['electric_main']] : null,
                'water_main_meter_id'    => $row['water_main'] ? $meters[$row['water_main']] : null,
                'description'            => $row['description'],
            ]);
        }

        $tenants = [];
        $moveIns = [];

        foreach ($data['tenants'] as $row) {
            $tenant = Tenant::create([
                'full_name'            => $row['full_name'],
                'phone'                => $row['phone'],
                'id_card_number'       => $row['id_card_number'],
                'room_id'              => $rooms[$row['room']]->id,
                'move_in_date'         => $row['move_in_date'],
                'move_out_date'        => $row['move_out_date'],
                'status'               => $row['status'],
                'start_electric_meter' => $row['start_electric_meter'],
                'start_water_meter'    => $row['start_water_meter'],
                'notes'                => trim(($row['notes'] ?? '') . ' (Imported from the paper records.)'),
            ]);
            $tenants[$row['key']] = $tenant;

            $deposit = $row['deposit'];
            $moveIns[$row['key']] = MoveInPayment::create([
                'tenant_id'     => $tenant->id,
                'room_id'       => $tenant->room_id,
                'paid_at'       => $deposit['paid_at'],
                'deposit_usd'   => $deposit['usd'],
                'prepaid_usd'   => 0,
                'exchange_rate' => $deposit['exchange_rate'],
                'method'        => $deposit['method'],
                'notes'         => $deposit['notes'],
            ]);
            DepositTransaction::create([
                'tenant_id'          => $tenant->id,
                'type'               => 'received',
                'amount_usd'         => $deposit['usd'],
                'occurred_on'        => $deposit['paid_at'],
                'description'        => $deposit['notes'] ?? 'Deposit received',
                'move_in_payment_id' => $moveIns[$row['key']]->id,
            ]);
        }

        $sequence = [];
        $invoices = [];

        $months = collect($data['months'])->sortBy(fn ($m) => "{$m['period']} {$m['room']} {$tenants[$m['tenant']]->move_in_date->toDateString()}");

        foreach ($months as $month) {
            $tenant = $tenants[$month['tenant']];
            $room = $rooms[$month['room']];
            $reading = null;

            if ($month['electric'] !== null) {
                $values = [];
                foreach (['electric', 'water'] as $utility) {
                    $u = $month[$utility];
                    $values += [
                        "{$utility}_previous" => $u['previous'],
                        "{$utility}_current"  => $u['current'],
                        "{$utility}_usage"    => $u['usage'],
                        "{$utility}_rate"     => $u['rate'],
                        "{$utility}_method"   => $u['method'],
                        "{$utility}_amount"   => $u['amount'],
                    ];
                }

                $reading = MeterReading::create($values + [
                    'room_id'      => $room->id,
                    'tenant_id'    => $tenant->id,
                    'period'       => $month['period'],
                    'total_amount' => $month['electric']['amount'] + $month['water']['amount'],
                    'read_at'      => $month['read_at'],
                    'notes'        => $month['notes'],
                    'locked_at'    => now(),
                ]);
            }

            $period = CarbonImmutable::createFromFormat('!Y-m', $month['period']);
            $rentPeriod = $period->addMonthNoOverflow();
            $sequence[$month['period']] = ($sequence[$month['period']] ?? 0) + 1;
            $rentRiel = $this->rentRiel($month);
            $items = (int) collect($month['items'])->sum('amount_riel');

            $invoice = Invoice::create([
                'invoice_number'            => sprintf('INV-%s-%04d', $period->format('Ym'), $sequence[$month['period']]),
                'kind'                      => 'monthly',
                'room_id'                   => $room->id,
                'tenant_id'                 => $tenant->id,
                'meter_reading_id'          => $reading?->id,
                'reading_locked_by_invoice' => false,
                'period'                    => $month['period'],
                'rent_period'               => $rentPeriod->format('Y-m'),
                'issue_date'                => $period->endOfMonth()->toDateString(),
                'due_date'                  => null,
                'room_number'               => $room->room_number,
                'tenant_name'               => $tenant->full_name,
                'tenant_phone'              => $tenant->phone,
                'rent_full_usd'             => $month['rent_usd'],
                'occupied_days'             => $month['rent_usd'] > 0 ? $rentPeriod->daysInMonth : 0,
                'days_in_month'             => $rentPeriod->daysInMonth,
                'rent_usd'                  => $month['rent_usd'],
                'exchange_rate'             => $month['exchange_rate'],
                'rent_riel'                 => $rentRiel,
            ] + $this->utilityColumns($month) + [
                'items_riel'                => $items,
                'electric_route'            => $month['electric_route'] ?? 'owner',
                'edc_status'                => $month['edc_status'] ?? null,
                'edc_checked_at'            => ($month['edc_status'] ?? null) === 'paid' ? now() : null,
                'edc_note'                  => $month['edc_note'] ?? null,
                'credit_riel'               => 0,
                'adjustment_riel'           => 0,
                'total_riel'                => $month['total_riel'],
                'paid_riel'                 => 0,
                'status'                    => 'unpaid',
                'notes'                     => trim('Opening balance (paper records). ' . ($month['notes'] ?? '')),
                'active_key'                => "{$tenant->id}-{$room->id}-{$month['period']}",
            ]);

            foreach ($month['items'] as $item) {
                $invoice->items()->create([
                    'type'        => $item['type'],
                    'description' => $item['description'],
                    'quantity'    => 1,
                    'unit_price'  => $item['amount_riel'],
                    'currency'    => 'KHR',
                    'amount_riel' => $item['amount_riel'],
                ]);
            }

            foreach ($month['payments'] as $payment) {
                InvoicePayment::create([
                    'invoice_id'      => $invoice->id,
                    'paid_at'         => $payment['paid_at'],
                    'currency'        => $payment['currency'],
                    'amount_received' => $payment['amount'],
                    'amount_riel'     => $this->paymentRiel($payment, $month),
                    'method'          => $payment['method'],
                    'reference'       => 'IMPORT',
                    'notes'           => $payment['notes'] ?? 'Paid before RTRroom (date not recorded)',
                ]);
            }

            $this->syncPaid($invoice);
            $invoices[$month['tenant']][$month['period']] = $invoice;
        }

        foreach ($data['tenants'] as $row) {
            if (! empty($row['settlement'])) {
                $this->settle($tenants[$row['key']], $row, $invoices[$row['key']] ?? []);
            }
        }
    }

    /**
     * Deposits of tenants who left. "hong" is the settlement written on the records:
     * $140 = September utilities 27,600 + room repair 226,400 + 320,000 riel returned (at 4,100).
     */
    private function settle(Tenant $tenant, array $row, array $invoices): void
    {
        $deposit = (float) $row['deposit']['usd'];
        $settle = $row['settlement'];
        $rate = 4100;
        $parts = match ($settle['kind']) {
            'hong'     => ['invoices' => round(27600 / $rate, 2), 'damage' => round(226400 / $rate, 2), 'refund' => round(320000 / $rate, 2)],
            default    => ['invoices' => 0.0, 'damage' => 0.0, 'refund' => $deposit],
        };

        if (abs(array_sum($parts) - $deposit) > 0.001) {
            throw new \RuntimeException("{$row['key']}: the settlement does not add up to the deposit.");
        }

        $settlement = DepositSettlement::create([
            'tenant_id'          => $tenant->id,
            'room_id'            => $tenant->room_id,
            'settled_on'         => $settle['settled_on'],
            'move_out_date'      => $tenant->move_out_date,
            'deposit_usd'        => $deposit,
            'invoices_usd'       => $parts['invoices'],
            'damage_usd'         => $parts['damage'],
            'overstay_days'      => 0,
            'overstay_daily_usd' => 0,
            'overstay_usd'       => 0,
            'other_usd'          => 0,
            'notice_given'       => true,
            'unused_days'        => 0,
            'unused_rent_usd'    => 0,
            'unused_credit_usd'  => 0,
            'refund_usd'         => $parts['refund'],
            'owed_usd'           => 0,
            'exchange_rate'      => $settle['kind'] === 'hong' ? $rate : (int) $row['deposit']['exchange_rate'],
            'notes'              => $settle['note'] ?? 'From the paper records: 226,400 riel room repair, 320,000 riel returned.',
        ]);

        if ($settle['kind'] === 'hong') {
            $final = $invoices['2026-09'];
            InvoicePayment::create([
                'invoice_id'      => $final->id,
                'paid_at'         => $settle['settled_on'],
                'currency'        => 'KHR',
                'amount_received' => $final->total_riel,
                'amount_riel'     => $final->total_riel,
                'method'          => 'deposit',
                'reference'       => "Settlement #{$settlement->id}",
            ]);
            $this->syncPaid($final);
        }

        $log = fn (string $type, float $amount, string $description) => $amount > 0 ? DepositTransaction::create([
            'tenant_id'     => $tenant->id,
            'type'          => $type,
            'amount_usd'    => $amount,
            'occurred_on'   => $settle['settled_on'],
            'description'   => $description,
            'settlement_id' => $settlement->id,
        ]) : null;

        $log('deduct_invoice', $parts['invoices'], 'Final utilities (27,600 riel)');
        $log('deduct_damage', $parts['damage'], 'Room repair (226,400 riel)');
        $log('refund', $parts['refund'], match ($settle['kind']) {
            'transfer' => 'Moved with the tenant to the new room',
            'hong'     => 'Returned to tenant (320,000 riel)',
            default    => 'Returned to tenant',
        });
    }

    /**
     * Totals in the database against the totals the file expects.
     */
    private function compare(array $expected): bool
    {
        $valid = Invoice::valid();
        $actual = [
            'rooms'               => Room::count(),
            'tenants'             => Tenant::count(),
            'invoices'            => (clone $valid)->count(),
            'meter_readings'      => MeterReading::count(),
            'electric_riel'       => (int) (clone $valid)->sum('electric_amount'),
            'electric_owner_riel' => (int) (clone $valid)->where('electric_route', 'owner')->sum('electric_amount'),
            'electric_via_owner_riel' => (int) (clone $valid)->where('electric_route', 'via_owner')->sum('electric_amount'),
            'electric_direct_riel' => (int) (clone $valid)->where('electric_route', 'direct')->sum('electric_amount'),
            'water_riel'          => (int) (clone $valid)->sum('water_amount'),
            'rent_usd'            => (float) (clone $valid)->sum('rent_usd'),
            'rent_riel'           => (int) (clone $valid)->sum('rent_riel'),
            'items_riel'          => (int) (clone $valid)->sum('items_riel'),
            'total_riel'          => (int) (clone $valid)->sum('total_riel'),
            'paid_riel'           => (int) InvoicePayment::sum('amount_riel'),
            'deposits_usd'        => round((float) MoveInPayment::sum('deposit_usd'), 2),
            'main_meter_readings' => MainMeterReading::count(),
        ];
        $unpaid = (clone $valid)->where('status', '!=', 'paid')->count();

        $rows = [];
        $ok = true;
        foreach ($expected as $key => $value) {
            $same = abs(($actual[$key] ?? 0) - $value) < 0.005;
            $ok = $ok && $same;
            $rows[] = [$key, $this->show($value), $this->show($actual[$key] ?? null), $same ? 'OK' : 'DIFFERENT'];
        }
        $rows[] = ['unpaid invoices', '0', (string) $unpaid, $unpaid === 0 ? 'OK' : 'DIFFERENT'];

        $this->table(['What', 'File', 'Database', ''], $rows);

        return $ok && $unpaid === 0;
    }

    private function utilityColumns(array $month): array
    {
        $columns = [];
        foreach (['electric', 'water'] as $utility) {
            $u = $month[$utility] ?? null;
            $columns += [
                "{$utility}_previous" => $u['previous'] ?? null,
                "{$utility}_current"  => $u['current'] ?? null,
                "{$utility}_usage"    => $u['usage'] ?? 0,
                "{$utility}_rate"     => $u['rate'] ?? null,
                "{$utility}_method"   => $u['method'] ?? null,
                "{$utility}_amount"   => $u['amount'] ?? 0,
                "{$utility}_note"     => null,
            ];
        }

        return $columns;
    }

    private function syncPaid(Invoice $invoice): void
    {
        $paid = (int) InvoicePayment::where('invoice_id', $invoice->id)->sum('amount_riel');
        $invoice->update([
            'paid_riel' => $paid,
            'status'    => $paid >= $invoice->total_riel ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'),
        ]);
    }

    // Electricity on the total: none when the tenant pays EDC directly
    private function chargedElectric(array $month): int
    {
        return ($month['electric_route'] ?? 'owner') === 'direct' ? 0 : (int) ($month['electric']['amount'] ?? 0);
    }

    private function rentRiel(array $month): int
    {
        return (int) (round($month['rent_usd'] * $month['exchange_rate'] / 100) * 100);
    }

    private function paymentRiel(array $payment, array $month): int
    {
        return $payment['currency'] === 'USD'
            ? (int) (round($payment['amount'] * $month['exchange_rate'] / 100) * 100)
            : (int) $payment['amount'];
    }

    private function show($value): string
    {
        return is_float($value) && floor($value) != $value ? number_format($value, 2) : number_format((float) $value);
    }
}
