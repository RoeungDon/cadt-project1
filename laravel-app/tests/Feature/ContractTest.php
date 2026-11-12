<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $roomNumber = 'A1', float $rent = 120): Tenant
    {
        $room = Room::create(['room_number' => $roomNumber, 'floor' => 1, 'rent_price' => $rent, 'status' => 'available']);

        return app(TenantService::class)->create([
            'full_name'            => 'Tenant ' . $roomNumber,
            'gender'               => 'female',
            'phone'                => '012000000',
            'room_id'              => $room->id,
            'move_in_date'         => '2026-11-05',
            'start_electric_meter' => 1250,
            'start_water_meter'    => 30,
        ]);
    }

    public function test_the_contract_is_filled_from_the_tenant_the_room_and_the_settings(): void
    {
        $tenant = $this->tenant();

        $this->putJson('/api/settings', [
            'landlord_name'        => 'Owner Test',
            'property_address'     => 'Street 1, Phnom Penh',
            'contract_term_months' => 6,
            'payment_due_days'     => 7,
            'contract_extra_terms' => "No pets.\n\nNo smoking inside.",
        ])->assertOk();

        $this->postJson("/api/tenants/{$tenant->id}/move-in-payment", [
            'deposit_usd' => 120, 'prepaid_usd' => 0, 'paid_at' => '2026-11-05', 'method' => 'cash', 'exchange_rate' => 4100,
        ])->assertSuccessful();

        $this->getJson("/api/tenants/{$tenant->id}/contract")
            ->assertOk()
            ->assertJsonPath('data.number', sprintf('RTR-2026-%04d', $tenant->id))
            ->assertJsonPath('data.start_date', '2026-11-05')
            ->assertJsonPath('data.end_date', '2027-05-04')
            ->assertJsonPath('data.term_months', 6)
            ->assertJsonPath('data.landlord.name', 'Owner Test')
            ->assertJsonPath('data.property_address', 'Street 1, Phnom Penh')
            ->assertJsonPath('data.tenant.name', 'Tenant A1')
            ->assertJsonPath('data.room.number', 'A1')
            ->assertJsonPath('data.room.rent_usd', 120)
            ->assertJsonPath('data.start_meters.electric', 1250)
            ->assertJsonPath('data.deposit_usd', 120)
            ->assertJsonPath('data.payment_due_days', 7)
            ->assertJsonPath('data.notice_days', 30)
            ->assertJsonPath('data.extra_terms', ['No pets.', 'No smoking inside.']);
    }

    public function test_a_tenant_reads_only_their_own_contract_in_the_portal(): void
    {
        $mine = $this->tenant('A1');
        $other = $this->tenant('B2');

        Sanctum::actingAs(User::factory()->create(['role' => 'tenant', 'tenant_id' => $mine->id, 'phone' => null]));

        $this->getJson('/api/portal/contract')->assertOk()->assertJsonPath('data.room.number', 'A1');
        $this->getJson("/api/tenants/{$other->id}/contract")->assertForbidden();
    }
}
