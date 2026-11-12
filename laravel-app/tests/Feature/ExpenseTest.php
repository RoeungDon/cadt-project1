<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Room;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 09:00:00');
        Setting::setValue('exchange_rate', 4100);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_monthly_expenses_are_made_once_a_month_and_can_be_paid_and_stopped(): void
    {
        $internet = $this->postJson('/api/recurring-expenses', [
            'category' => 'internet', 'name' => 'Internet', 'amount' => 15, 'currency' => 'USD', 'day_of_month' => 1, 'starts_on' => '2026-09-01',
        ])->assertCreated()->json('data');

        // Opening November makes September, October and November once
        $november = $this->getJson('/api/expenses?period=2026-11')->assertOk();
        $november->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'due')->assertJsonPath('data.0.amount_riel', 61500)
            ->assertJsonPath('totals.due', 61500);
        $this->getJson('/api/expenses?period=2026-11')->assertOk();
        $this->assertSame(3, Expense::count());

        $id = $november->json('data.0.id');
        $this->postJson("/api/expenses/{$id}/pay", ['paid_on' => '2026-11-01', 'method' => 'bank'])->assertOk()->assertJsonPath('data.status', 'paid');

        // Stopped: the months made stay, no new month after November
        $this->deleteJson("/api/recurring-expenses/{$internet['id']}")->assertOk();
        Carbon::setTestNow('2026-12-05');
        $this->getJson('/api/expenses?period=2026-12')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(3, Expense::count());
    }

    public function test_an_expense_with_a_receipt_in_dollars_and_only_the_owner_sees_expenses(): void
    {
        Storage::fake('local');
        $room = Room::create(['room_number' => 'A1', 'rent_price' => 100, 'status' => 'available']);

        $expense = $this->post('/api/expenses', [
            'category' => 'repair', 'description' => 'New door lock', 'spent_on' => '2026-11-08', 'amount' => 12.5, 'currency' => 'USD',
            'status' => 'paid', 'paid_on' => '2026-11-08', 'method' => 'cash', 'room_id' => $room->id,
            'receipt' => UploadedFile::fake()->image('lock.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $this->assertSame(51300, $expense['amount_riel']); // 12.5 × 4,100 = 51,250 → 51,300
        $this->assertSame('2026-11', $expense['period']);
        $this->assertSame('A1', $expense['room_number']);
        $this->assertStringContainsString('signature=', $expense['receipt_url']);
        Storage::disk('local')->assertExists(Expense::find($expense['id'])->receipt_path);

        // "Due" needs no paid date; "paid" does
        $this->putJson("/api/expenses/{$expense['id']}", ['category' => 'repair', 'description' => 'New door lock', 'spent_on' => '2026-11-08',
            'amount' => 12.5, 'currency' => 'USD', 'status' => 'paid'])->assertStatus(422)->assertJsonValidationErrors('paid_on');

        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));
        $this->getJson('/api/expenses')->assertForbidden();
        $this->getJson('/api/reports/profit?from=2026-10&to=2026-11')->assertForbidden();
    }

    public function test_profit_counts_only_the_owners_money_and_shows_lost_rent(): void
    {
        Carbon::setTestNow('2026-11-30 09:00:00');
        // E1 has its own EDC meter, S1 is on the owner's sub-meter, V1 stays empty all November
        $edc = Room::create(['room_number' => 'E1', 'rent_price' => 100, 'status' => 'occupied', 'electric_paid_to' => 'edc', 'water_rate_type' => 'per_unit', 'water_custom_rate' => 2000]);
        $sub = Room::create(['room_number' => 'S1', 'rent_price' => 100, 'status' => 'occupied', 'electric_rate_type' => 'per_unit', 'electric_custom_rate' => 1000, 'water_rate_type' => 'per_unit', 'water_custom_rate' => 2000]);
        Room::create(['room_number' => 'V1', 'rent_price' => 60, 'status' => 'available']);
        foreach ([$edc, $sub] as $room) {
            Tenant::create(['full_name' => "T{$room->room_number}", 'phone' => '012', 'room_id' => $room->id, 'status' => 'active',
                'move_in_date' => '2026-10-01', 'start_electric_meter' => 100, 'start_water_meter' => 10]);
        }
        $this->postJson('/api/meter-readings', ['period' => '2026-11', 'readings' => [
            ['room_id' => $edc->id, 'electric_current' => 150, 'water_current' => 15],
            ['room_id' => $sub->id, 'electric_current' => 120, 'water_current' => 13],
        ]])->assertSuccessful();
        $this->postJson('/api/invoices/generate', ['period' => '2026-11'])->assertSuccessful();

        $this->postJson('/api/expenses', ['category' => 'garbage', 'description' => 'Garbage', 'spent_on' => '2026-11-01',
            'amount' => 25000, 'currency' => 'KHR', 'status' => 'due'])->assertCreated();

        $report = $this->getJson('/api/reports/profit?from=2026-11&to=2026-11')->assertOk()->json('data');
        $month = $report['months'][0];

        // Rent 2 × $100 at 4,100 + water (5 + 3) × 2,000 + S1 electricity 20 × 1,000; E1's EDC electricity is not income
        $this->assertSame(820000, $month['rent']);
        $this->assertSame(16000, $month['water']);
        $this->assertSame(20000, $month['electric']);
        $this->assertSame(856000, $month['income']);
        $this->assertGreaterThan(0, $month['edc_direct']);
        $this->assertSame(1, $month['edc_unchecked']);
        $this->assertSame(25000, $month['expenses']);
        $this->assertSame(25000, $month['expenses_due']);
        $this->assertSame(831000, $month['net']);

        // V1 empty 30 days: $60 at 4,100
        $this->assertSame(30, $month['vacant_days']);
        $this->assertSame(246000, $month['lost_rent_riel']);
        $rooms = collect($report['rooms'])->keyBy('room_number');
        $this->assertSame(246000, $rooms['V1']['lost_rent_riel']);
        $this->assertSame(-25000, $rooms['Shared']['net']);
    }
}
