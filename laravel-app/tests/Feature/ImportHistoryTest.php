<?php

namespace Tests\Feature;

use App\Models\DepositTransaction;
use App\Models\Invoice;
use App\Models\MainMeterReading;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\DepositService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportHistoryTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam(sys_get_temp_dir(), 'rtr') . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    // Room R1: Dara left after September (deposit returned), Sok moved in. Room R2 is the owner's.
    private function data(): array
    {
        $month = fn ($tenant, $period, $e, $w, $rent, $paid = null) => [
            'tenant' => $tenant, 'room' => 'R1', 'period' => $period, 'read_at' => "{$period}-28",
            'electric' => ['previous' => $e[0], 'current' => $e[1], 'usage' => $e[1] - $e[0], 'rate' => 1000, 'method' => 'per_unit', 'amount' => ($e[1] - $e[0]) * 1000],
            'water' => ['previous' => $w[0], 'current' => $w[1], 'usage' => $w[1] - $w[0], 'rate' => 2000, 'method' => 'per_unit', 'amount' => ($w[1] - $w[0]) * 2000],
            'rent_usd' => $rent, 'exchange_rate' => 4000, 'items' => [],
            'total_riel' => $rent * 4000 + ($e[1] - $e[0]) * 1000 + ($w[1] - $w[0]) * 2000,
            'payments' => [['paid_at' => "{$period}-28", 'currency' => 'KHR', 'amount' => $paid ?? $rent * 4000 + ($e[1] - $e[0]) * 1000 + ($w[1] - $w[0]) * 2000, 'method' => 'cash', 'notes' => null]],
            'notes' => null,
        ];
        $room = fn ($number, $status, $main) => [
            'room_number' => $number, 'floor' => 0, 'rent_price' => 100, 'status' => $status, 'electric' => ['per_unit', 1000],
            'water' => ['per_unit', 2000], 'edc_account' => null, 'electric_main' => $main, 'water_main' => null, 'description' => null,
        ];
        $tenant = fn ($key, $in, $out, $e, $w, $settlement = null) => [
            'key' => $key, 'room' => 'R1', 'full_name' => ucfirst($key), 'phone' => '012000000', 'id_card_number' => null,
            'move_in_date' => $in, 'move_out_date' => $out, 'status' => $out ? 'moved_out' : 'active',
            'start_electric_meter' => $e, 'start_water_meter' => $w, 'notes' => null,
            'deposit' => ['usd' => 100, 'paid_at' => $in, 'method' => 'cash', 'exchange_rate' => 4000, 'notes' => null],
            'settlement' => $settlement,
        ];

        return [
            'version' => 1,
            'rooms' => [$room('R1', 'occupied', 'E1'), $room('R2', 'owner_use', 'E1')],
            'main_meters' => [['utility' => 'electric', 'account' => 'E1', 'name' => 'EDC', 'tier_mode' => 'whole', 'extra_percent' => 0, 'monthly_fee' => 0,
                'readings' => [['period' => '2026-09', 'reading' => 100, 'read_on' => '2026-09-28', 'usage_override' => null, 'note' => null],
                    ['period' => '2026-10', 'reading' => 180, 'read_on' => '2026-10-28', 'usage_override' => null, 'note' => null]]]],
            'tenants' => [
                $tenant('dara', '2026-08-01', '2026-09-30', 10, 1, ['kind' => 'refund', 'settled_on' => '2026-09-30', 'note' => 'Returned in full.']),
                $tenant('sok', '2026-10-01', null, 40, 9),
            ],
            'months' => [
                $month('dara', '2026-08', [10, 25], [1, 5], 100),
                $month('dara', '2026-09', [25, 40], [5, 9], 0),
                $month('sok', '2026-10', [40, 70], [9, 12], 100),
            ],
            'checks' => [
                'rooms' => 2, 'tenants' => 2, 'invoices' => 3, 'meter_readings' => 3,
                'electric_riel' => 60000, 'water_riel' => 22000, 'rent_usd' => 200, 'rent_riel' => 800000, 'items_riel' => 0,
                'total_riel' => 882000, 'paid_riel' => 882000, 'deposits_usd' => 200, 'main_meter_readings' => 2,
            ],
        ];
    }

    private function write(array $data): void
    {
        file_put_contents($this->file, json_encode($data));
    }

    public function test_the_check_saves_nothing_and_force_saves_everything_as_paid(): void
    {
        $this->write($this->data());

        $this->artisan('rtr:import', ['--file' => $this->file])->expectsOutputToContain('Check passed')->assertExitCode(0);
        $this->assertSame(0, Room::count());

        $this->artisan('rtr:import', ['--file' => $this->file, '--force' => true])
            ->expectsQuestion('Type IMPORT to save', 'IMPORT')
            ->expectsOutputToContain('Saved')
            ->assertExitCode(0);

        $this->assertSame(3, Invoice::where('status', 'paid')->count());
        $this->assertSame(['INV-202608-0001', 'INV-202609-0001', 'INV-202610-0001'], Invoice::orderBy('id')->pluck('invoice_number')->all());
        $this->assertSame('owner_use', Room::where('room_number', 'R2')->value('status'));
        $this->assertSame(2, MainMeterReading::count());

        // Dara's deposit went back, Sok's is held; Sok's November starts where October ended
        $deposits = app(DepositService::class);
        $this->assertSame(0.0, $deposits->balance(Tenant::where('full_name', 'Dara')->first()));
        $this->assertSame(100.0, $deposits->balance(Tenant::where('full_name', 'Sok')->first()));
        $this->assertSame(1, DepositTransaction::where('type', 'refund')->count());

        // Running it again is refused
        $this->artisan('rtr:import', ['--file' => $this->file])->expectsOutputToContain('already has data')->assertExitCode(1);
    }

    public function test_a_wrong_answer_or_a_broken_record_saves_nothing(): void
    {
        $this->write($this->data());
        $this->artisan('rtr:import', ['--file' => $this->file, '--force' => true])
            ->expectsQuestion('Type IMPORT to save', 'yes')
            ->assertExitCode(1);
        $this->assertSame(0, Tenant::count());

        // September does not start where August ended, and a payment is short
        $data = $this->data();
        $data['months'][1]['electric']['previous'] = 26;
        $data['months'][2]['payments'][0]['amount'] = 1000;
        $this->write($data);

        $this->artisan('rtr:import', ['--file' => $this->file, '--force' => true])
            ->expectsOutputToContain('the month before ended at 25')
            ->expectsOutputToContain('payments add up to 1000')
            ->expectsOutputToContain('Nothing was saved')
            ->assertExitCode(1);
        $this->assertSame(0, Room::count());
    }
}
