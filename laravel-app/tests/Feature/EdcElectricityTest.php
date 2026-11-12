<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\MeterReading;
use App\Models\Room;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EdcElectricityTest extends TestCase
{
    use RefreshDatabase;

    // Room E1 has its own EDC meter (tenant pays EDC), room S1 is on the owner's sub-meter at 1,000 riel/kWh
    private function rooms(): array
    {
        Setting::setValue('exchange_rate', 4000);
        $edc = $this->postJson('/api/rooms', ['room_number' => 'E1', 'rent_price' => 100, 'edc_account' => '98448710', 'electric_paid_to' => 'edc'])
            ->assertCreated()->assertJsonPath('data.electric_paid_to', 'edc')->json('data');
        $sub = Room::create(['room_number' => 'S1', 'rent_price' => 100, 'status' => 'available', 'electric_rate_type' => 'per_unit', 'electric_custom_rate' => 1000]);

        foreach ([[$edc['id'], 'Dara'], [$sub->id, 'Sok']] as [$roomId, $name]) {
            Tenant::create(['full_name' => $name, 'phone' => '012', 'room_id' => $roomId, 'status' => 'active',
                'move_in_date' => '2026-08-01', 'start_electric_meter' => 100, 'start_water_meter' => 10]);
            Room::whereKey($roomId)->update(['status' => 'occupied']);
        }

        return [Room::find($edc['id']), $sub];
    }

    private function readings(string $period, int $electric, int $water): void
    {
        $this->postJson('/api/meter-readings', ['period' => $period, 'readings' => Room::orderBy('room_number')->get()->map(fn ($room) => [
            'room_id' => $room->id, 'electric_current' => $electric, 'water_current' => $water,
        ])->all()])->assertSuccessful();
    }

    public function test_an_edc_room_shows_electricity_but_does_not_charge_it(): void
    {
        [$edc, $sub] = $this->rooms();
        $this->readings('2026-10', 120, 15); // 20 kWh, 5 m³

        $preview = collect($this->getJson('/api/invoices/preview?period=2026-10')->assertOk()->json('data'))->keyBy('room_number');
        $this->assertTrue($preview['E1']['electric_direct']);
        $this->assertSame(0, $preview['E1']['total_riel'] - $preview['E1']['rent_riel'] - $preview['E1']['water']);

        $this->postJson('/api/invoices/generate', ['period' => '2026-10'])->assertSuccessful();
        $direct = Invoice::where('room_id', $edc->id)->first();
        $owner = Invoice::where('room_id', $sub->id)->first();

        $this->assertSame('direct', $direct->electric_route);
        $this->assertSame('unchecked', $direct->edc_status);
        $this->assertGreaterThan(0, $direct->electric_amount);                        // shown on the invoice
        $this->assertSame($direct->rent_riel + $direct->water_amount, $direct->total_riel); // not charged
        $this->assertSame('owner', $owner->electric_route);
        $this->assertSame($owner->rent_riel + 20000 + $owner->water_amount, $owner->total_riel);

        // The tenant gave the money to the owner: on the total; then back to paying EDC directly
        $this->postJson("/api/invoices/{$direct->id}/electric-route", ['route' => 'via_owner'])->assertOk()
            ->assertJsonPath('data.total_riel', $direct->total_riel + $direct->electric_amount)
            ->assertJsonPath('data.electric_charged', $direct->electric_amount);
        $this->postJson("/api/invoices/{$direct->id}/electric-route", ['route' => 'direct'])->assertOk()
            ->assertJsonPath('data.total_riel', $direct->total_riel);

        // A sub-meter room has no EDC bill of its own
        $this->postJson("/api/invoices/{$owner->id}/electric-route", ['route' => 'direct'])->assertStatus(422);
        $this->postJson("/api/invoices/{$owner->id}/edc-status", ['status' => 'paid'])->assertStatus(422);
    }

    public function test_the_old_balance_on_the_next_edc_bill_decides_and_unpaid_becomes_debt(): void
    {
        [$edc] = $this->rooms();
        $this->readings('2026-10', 120, 15);
        $this->postJson('/api/invoices/generate', ['period' => '2026-10'])->assertSuccessful();
        $invoice = Invoice::where('room_id', $edc->id)->first();

        $this->getJson('/api/invoices/edc-check')->assertOk()->assertJsonCount(1, 'data');

        // 60 riel left is only rounding: paid
        $this->postJson("/api/invoices/{$invoice->id}/edc-status", ['old_balance' => 60])->assertOk()
            ->assertJsonPath('data.edc_status', 'paid');
        $this->getJson('/api/invoices/edc-check')->assertOk()->assertJsonCount(0, 'data');

        // The whole bill still owed: not paid, then the owner pays EDC and the tenant owes it
        $this->postJson("/api/invoices/{$invoice->id}/edc-status", ['old_balance' => $invoice->electric_amount])->assertOk()
            ->assertJsonPath('data.edc_status', 'unpaid');
        $this->postJson("/api/invoices/{$invoice->id}/payments", ['paid_at' => '2026-10-31', 'currency' => 'KHR', 'amount' => $invoice->total_riel, 'method' => 'cash'])
            ->assertOk()->assertJsonPath('data.status', 'paid');
        $this->postJson("/api/invoices/{$invoice->id}/electric-route", ['route' => 'via_owner'])->assertOk()
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.balance_riel', $invoice->electric_amount);

        // Going back to "direct" would leave the invoice overpaid: refused until a payment is removed
        $this->postJson("/api/invoices/{$invoice->id}/payments", ['paid_at' => '2026-11-05', 'currency' => 'KHR', 'amount' => $invoice->electric_amount, 'method' => 'cash'])->assertOk();
        $this->postJson("/api/invoices/{$invoice->id}/electric-route", ['route' => 'direct'])->assertStatus(422);

        // Viewers cannot change it
        Sanctum::actingAs(User::factory()->create(['role' => 'viewer']));
        $this->postJson("/api/invoices/{$invoice->id}/edc-status", ['status' => 'paid'])->assertForbidden();
    }

    public function test_usage_more_than_half_away_from_the_last_months_is_flagged(): void
    {
        [$edc] = $this->rooms();
        $tenant = Tenant::where('room_id', $edc->id)->first();
        foreach (['2026-07' => [100, 120], '2026-08' => [120, 140], '2026-09' => [140, 160]] as $period => [$from, $to]) {
            MeterReading::create(['room_id' => $edc->id, 'tenant_id' => $tenant->id, 'period' => $period, 'read_at' => "{$period}-28",
                'electric_previous' => $from, 'electric_current' => $to, 'electric_usage' => 20, 'electric_rate' => 480, 'electric_amount' => 9600,
                'water_previous' => 10, 'water_current' => 15, 'water_usage' => 5, 'water_rate' => 2000, 'water_amount' => 10000, 'total_amount' => 19600]);
        }

        // October: 40 kWh (+100%) and 5 m³ (normal)
        $this->postJson('/api/meter-readings', ['period' => '2026-10', 'readings' => [
            ['room_id' => $edc->id, 'electric_current' => 200, 'water_current' => 20],
        ]])->assertSuccessful();

        $row = collect($this->getJson('/api/meter-readings?period=2026-10')->assertOk()->json('data'))->firstWhere('room_number', 'E1');
        $this->assertEquals([['utility' => 'electric', 'usage' => 40, 'average' => 20, 'change_percent' => 100]], $row['usage_alerts']);
        $this->assertSame('edc', $row['electric_paid_to']);
    }
}
