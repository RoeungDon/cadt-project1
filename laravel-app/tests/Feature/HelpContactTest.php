<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HelpContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tenant_sees_the_owner_contact_on_the_help_page_but_staff_routes_stay_closed(): void
    {
        $this->putJson('/api/settings', ['landlord_name' => 'Owner Test', 'landlord_phone' => '010 000 001'])->assertOk();

        $room = Room::create(['room_number' => 'A1', 'floor' => 1, 'rent_price' => 100, 'status' => 'available']);
        $tenant = app(TenantService::class)->create(['full_name' => 'Tenant', 'gender' => 'male', 'phone' => '012000000', 'room_id' => $room->id, 'move_in_date' => '2026-11-01']);

        Sanctum::actingAs(User::factory()->create(['role' => 'tenant', 'tenant_id' => $tenant->id, 'phone' => null]));

        $this->getJson('/api/portal/contact')
            ->assertOk()
            ->assertJsonPath('data.name', 'Owner Test')
            ->assertJsonPath('data.phone', '010 000 001');

        $this->getJson('/api/settings')->assertForbidden();
    }
}
