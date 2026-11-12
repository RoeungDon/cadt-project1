<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MainMeterTest extends TestCase
{
    use RefreshDatabase;

    private function waterMeter(): array
    {
        return $this->postJson('/api/main-meters', [
            'utility' => 'water', 'name' => 'PPWSA main', 'account' => 'W801689',
            'tier_mode' => 'progressive', 'extra_percent' => 10, 'monthly_fee' => 750,
        ])->assertCreated()->json('data');
    }

    // Room A1 rented at 2,000 riel/m³, room A2 used by the owner, both behind the PPWSA meter
    private function building(int $meterId): Room
    {
        $rented = Room::create(['room_number' => 'A1', 'rent_price' => 100, 'status' => 'available',
            'water_rate_type' => 'per_unit', 'water_custom_rate' => 2000, 'water_main_meter_id' => $meterId]);
        $this->postJson('/api/rooms', ['room_number' => 'A2', 'rent_price' => 150, 'status' => 'owner_use', 'water_main_meter_id' => $meterId])
            ->assertCreated()->assertJsonPath('data.status', 'owner_use');

        Tenant::create(['full_name' => 'Dara', 'phone' => '011', 'room_id' => $rented->id, 'status' => 'active',
            'move_in_date' => '2026-10-01', 'start_electric_meter' => 100, 'start_water_meter' => 10]);
        $rented->update(['status' => 'occupied']);

        $this->postJson('/api/meter-readings', ['period' => '2026-10', 'readings' => [
            ['room_id' => $rented->id, 'electric_current' => 120, 'water_current' => 30],
        ]])->assertSuccessful();

        return $rented;
    }

    public function test_the_month_balance_compares_the_main_meter_with_the_rooms(): void
    {
        $meter = $this->waterMeter();
        $this->building($meter['id']);

        $this->postJson("/api/main-meters/{$meter['id']}/readings", ['period' => '2026-09', 'reading' => 100, 'read_on' => '2026-09-28'])->assertOk();
        $balance = $this->postJson("/api/main-meters/{$meter['id']}/readings", ['period' => '2026-10', 'reading' => 140, 'read_on' => '2026-10-28'])
            ->assertOk()->json('data');

        // 40 m³: 7×400 + 8×720 + 10×960 + 15×1,250 = 36,910 → 36,900; +10% sewage + 750 fee = 41,340 → 41,300
        $this->assertSame(40.0, (float) $balance['usage']);
        $this->assertSame(20.0, (float) $balance['room_usage']);
        $this->assertSame(20.0, (float) $balance['unmetered']);
        $this->assertSame(41300, $balance['cost']);
        $this->assertSame(40000, $balance['collected']);
        $this->assertSame(-1300, $balance['balance']);
        $this->assertSame(1375, $balance['next_unit_cost']);
        $this->assertEqualsCanonicalizing(['unmetered', 'loss'], array_column($balance['alerts'], 'type'));

        $listed = $this->getJson('/api/main-meters?period=2026-10')->assertOk()->json('data.0');
        $this->assertSame(['A1', 'A2'], $listed['room_numbers']);

        $report = $this->getJson('/api/reports/utility-balance?from=2026-09&to=2026-10')->assertOk()->json('data.0');
        $this->assertCount(2, $report['months']);
        $this->assertNull($report['months'][0]['usage']); // no reading before September
        $this->assertSame(1, $report['totals']['months']);
        $this->assertSame(-1300, $report['totals']['balance']);
    }

    public function test_a_lower_reading_needs_the_usage_of_a_replaced_meter(): void
    {
        $meter = $this->waterMeter();
        $url = "/api/main-meters/{$meter['id']}/readings";

        $this->postJson($url, ['period' => '2026-01', 'reading' => 2256, 'read_on' => '2026-01-28'])->assertOk();
        $this->postJson($url, ['period' => '2026-02', 'reading' => 31, 'read_on' => '2026-02-28'])->assertStatus(422)->assertJsonValidationErrors('reading');
        $balance = $this->postJson($url, ['period' => '2026-02', 'reading' => 31, 'read_on' => '2026-02-28', 'usage_override' => 81, 'note' => 'New PPWSA meter'])
            ->assertOk()->json('data');
        $this->assertSame(81.0, (float) $balance['usage']);
    }

    public function test_roles_and_the_owner_use_status(): void
    {
        $meter = $this->waterMeter();
        $this->building($meter['id']);

        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));
        $this->postJson('/api/main-meters', ['utility' => 'water', 'name' => 'X', 'tier_mode' => 'whole'])->assertForbidden();
        $this->postJson("/api/main-meters/{$meter['id']}/readings", ['period' => '2026-10', 'reading' => 140, 'read_on' => '2026-10-28'])->assertOk();
        $this->getJson('/api/reports/utility-balance?from=2026-09&to=2026-10')->assertForbidden();

        // Viewers see the readings, not the money
        Sanctum::actingAs(User::factory()->create(['role' => 'viewer']));
        $balance = $this->getJson('/api/main-meters?period=2026-10')->assertOk()->json('data.0.balance');
        $this->assertArrayNotHasKey('cost', $balance);
        $this->assertArrayNotHasKey('collected', $balance);

        // A room the owner uses cannot get a tenant, and an electric meter cannot be linked as water
        Sanctum::actingAs(User::factory()->create(['role' => 'owner']));
        $ownerRoom = Room::where('room_number', 'A2')->first();
        $this->postJson('/api/tenants', ['full_name' => 'Sok', 'phone' => '012345678', 'room_id' => $ownerRoom->id, 'move_in_date' => '2026-11-01'])
            ->assertStatus(422)->assertJsonValidationErrors('room_id');
        $electric = $this->postJson('/api/main-meters', ['utility' => 'electric', 'name' => 'EDC owner', 'account' => '54917410', 'tier_mode' => 'whole'])->json('data');
        $this->putJson("/api/rooms/{$ownerRoom->id}", ['water_main_meter_id' => $electric['id']])->assertStatus(422);
        $this->putJson("/api/rooms/{$ownerRoom->id}", ['electric_main_meter_id' => $electric['id'], 'edc_account' => '54917410'])->assertOk()
            ->assertJsonPath('data.electric_main_meter_id', $electric['id']);

        // A meter with readings cannot be deleted
        $this->deleteJson("/api/main-meters/{$meter['id']}")->assertStatus(422);
        $this->deleteJson("/api/main-meters/{$electric['id']}")->assertOk();
        $this->assertNull($ownerRoom->fresh()->electric_main_meter_id);
    }
}
