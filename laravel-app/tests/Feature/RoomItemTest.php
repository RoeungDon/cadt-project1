<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\User;
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RoomItemTest extends TestCase
{
    use RefreshDatabase;

    private function room(string $number): Room
    {
        return Room::create(['room_number' => $number, 'floor' => 1, 'rent_price' => 100, 'status' => 'available']);
    }

    private function items(): array
    {
        return [
            'key_count' => 2,
            'items' => [
                ['name' => 'Bed', 'quantity' => 1, 'condition' => 'Good', 'note' => ''],
                ['name' => 'Fan', 'quantity' => 2, 'condition' => null, 'note' => 'Ceiling'],
            ],
        ];
    }

    public function test_the_room_list_is_saved_copied_and_kept_on_the_contract_from_the_handover_day(): void
    {
        $a1 = $this->room('A1');
        $a2 = $this->room('A2');

        $this->putJson("/api/rooms/{$a1->id}/items", $this->items())
            ->assertOk()
            ->assertJsonPath('data.key_count', 2)
            ->assertJsonPath('data.items.1.name', 'Fan')
            ->assertJsonPath('data.items.0.note', null);

        $this->postJson("/api/rooms/{$a1->id}/items/copy", ['room_ids' => [$a2->id, $a1->id]])
            ->assertOk()->assertJsonPath('count', 1);
        $this->getJson("/api/rooms/{$a2->id}/items")->assertJsonCount(2, 'data.items');

        // Moving in keeps the list of that day
        $tenant = app(TenantService::class)->create([
            'full_name' => 'Tenant A1', 'gender' => 'male', 'phone' => '012000000',
            'room_id' => $a1->id, 'move_in_date' => '2026-11-05',
        ]);

        $this->putJson("/api/rooms/{$a1->id}/items", ['key_count' => 1, 'items' => [['name' => 'Table', 'quantity' => 1]]])->assertOk();

        $this->getJson("/api/tenants/{$tenant->id}/contract")
            ->assertOk()
            ->assertJsonPath('data.handover.key_count', 2)
            ->assertJsonCount(2, 'data.handover.items')
            ->assertJsonPath('data.handover.items.0.name', 'Bed');

        // The owner can take the room's list again
        $this->postJson("/api/tenants/{$tenant->id}/handover")
            ->assertOk()
            ->assertJsonPath('data.handover.key_count', 1)
            ->assertJsonPath('data.handover.items.0.name', 'Table');
    }

    public function test_a_viewer_reads_the_list_but_cannot_change_it(): void
    {
        $room = $this->room('B1');
        Sanctum::actingAs(User::factory()->create(['role' => 'viewer']));

        $this->getJson("/api/rooms/{$room->id}/items")->assertOk()->assertJsonPath('data.items', []);
        $this->putJson("/api/rooms/{$room->id}/items", $this->items())->assertForbidden();
    }
}
