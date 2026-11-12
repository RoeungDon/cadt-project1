<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Room;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(Tenant $tenant, string $period, array $money, ?string $paidOn = null): Invoice
    {
        $total = $money['rent_riel'] + ($money['water_amount'] ?? 0) + (($money['electric_route'] ?? 'owner') === 'direct' ? 0 : ($money['electric_amount'] ?? 0));
        $invoice = Invoice::create(array_merge([
            'invoice_number' => "T-{$tenant->id}-{$period}", 'room_id' => $tenant->room_id, 'tenant_id' => $tenant->id, 'period' => $period,
            'issue_date' => "{$period}-28", 'due_date' => "{$period}-28", 'room_number' => $tenant->room->room_number, 'tenant_name' => $tenant->full_name,
            'rent_full_usd' => 100, 'occupied_days' => 30, 'days_in_month' => 30, 'rent_usd' => 100, 'exchange_rate' => 4000,
            'total_riel' => $total, 'paid_riel' => $paidOn ? $total : 0, 'status' => $paidOn ? 'paid' : 'unpaid', 'active_key' => "k-{$tenant->id}-{$period}",
        ], $money));
        if ($paidOn) {
            $invoice->payments()->create(['paid_at' => $paidOn, 'currency' => 'KHR', 'amount_received' => $total, 'amount_riel' => $total, 'method' => 'bank']);
        }

        return $invoice;
    }

    public function test_the_owner_sees_cards_and_charts_for_the_newest_invoice_month(): void
    {
        Carbon::setTestNow('2026-11-10');
        Setting::setValue('exchange_rate', '4000');
        Sanctum::actingAs(User::factory()->create(['role' => 'owner']));

        $a = Room::create(['room_number' => 'A1', 'floor' => 1, 'rent_price' => 100, 'status' => 'occupied']);
        $b = Room::create(['room_number' => 'A2', 'floor' => 1, 'rent_price' => 100, 'status' => 'occupied', 'electric_paid_to' => 'edc']);
        $start = ['status' => 'active', 'move_in_date' => '2026-08-01', 'start_electric_meter' => 0, 'start_water_meter' => 0, 'phone' => '012'];
        $sok = Tenant::create(array_merge($start, ['full_name' => 'Sok', 'room_id' => $a->id]));
        $dara = Tenant::create(array_merge($start, ['full_name' => 'Dara', 'room_id' => $b->id]));

        $this->invoice($sok, '2026-09', ['rent_riel' => 400000, 'water_amount' => 10000, 'electric_amount' => 20000], '2026-10-01');
        $this->invoice($sok, '2026-10', ['rent_riel' => 400000, 'water_amount' => 12000, 'electric_amount' => 30000], '2026-10-30');
        // Dara pays EDC herself: shown, but not income, and still unpaid after the due date
        $this->invoice($dara, '2026-10', ['rent_riel' => 400000, 'water_amount' => 8000, 'electric_amount' => 50000, 'electric_route' => 'direct']);
        Expense::create(['category' => 'internet', 'description' => 'Internet', 'spent_on' => '2026-10-01', 'period' => '2026-10',
            'amount' => 60000, 'currency' => 'KHR', 'exchange_rate' => 4000, 'amount_riel' => 60000, 'status' => 'paid', 'paid_on' => '2026-10-01', 'method' => 'cash']);

        $res = $this->getJson('/api/dashboard/overview')->assertOk();
        $d = $res->json('data');

        $this->assertSame('2026-10', $d['period']);
        $this->assertSame(['2026-10', '2026-09'], $res->json('periods'));
        $this->assertSame(850000, $d['kpis']['income']['value']);          // 400k+12k+30k + 400k+8k (no EDC)
        $this->assertEquals(97.7, $d['kpis']['income']['change']);         // vs 430,000
        $this->assertSame(442000, $d['kpis']['collected']['value']);
        $this->assertSame(850000, $d['kpis']['collected']['of']);
        $this->assertSame(790000, $d['kpis']['net']['value']);
        $this->assertSame(408000, $d['kpis']['outstanding']['value']);
        $this->assertSame(1, $d['kpis']['outstanding']['overdue']);
        $this->assertSame(['occupied' => 2, 'rooms' => 2], array_intersect_key($d['kpis']['occupancy'], ['occupied' => 1, 'rooms' => 1]));
        $this->assertCount(12, $d['trend']);
        $this->assertSame(50000, $d['not_income']['edc_direct']);
        $this->assertSame(800000, $d['income_mix']['rent']);
        $this->assertSame(442000, $d['collection']['total']);
        $this->assertSame('A2', $d['rooms'][1]['room_number']);
        $this->assertSame('unpaid', $d['rooms'][1]['status']);
        $this->assertSame(['overdue', 'A2', '2026-10'], [$d['alerts'][0]['type'], $d['alerts'][0]['room'], $d['alerts'][0]['period']]);
        $this->assertSame(408000, $d['alerts'][0]['amount']);
        $this->assertSame('in', $d['recent'][0]['type']);
        $this->assertSame(['A1', 'A2'], array_column($d['room_income'], 'room_number'));

        // Another month can be chosen
        $this->getJson('/api/dashboard/overview?period=2026-09')->assertOk()->assertJsonPath('data.kpis.income.value', 430000);
    }

    public function test_the_menu_counts_and_the_next_meter_reading(): void
    {
        Carbon::setTestNow('2026-11-10');
        Setting::setValue('exchange_rate', '4100');
        $room = Room::create(['room_number' => 'A1', 'floor' => 1, 'rent_price' => 100, 'status' => 'occupied', 'electric_paid_to' => 'edc']);
        $tenant = Tenant::create(['full_name' => 'Sok', 'room_id' => $room->id, 'status' => 'active', 'move_in_date' => '2026-08-01',
            'start_electric_meter' => 0, 'start_water_meter' => 0, 'phone' => '012']);
        $this->invoice($tenant, '2026-09', ['rent_riel' => 400000, 'electric_amount' => 9000, 'electric_route' => 'direct'], '2026-10-01');
        $this->invoice($tenant, '2026-10', ['rent_riel' => 400000, 'electric_amount' => 9000, 'electric_route' => 'direct']);

        // Staff see the counts too, but never expenses
        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));
        $this->getJson('/api/dashboard/badges')->assertOk()
            ->assertJsonPath('data.counts.invoices_unpaid', 1)
            ->assertJsonPath('data.counts.invoices_overdue', 1)
            ->assertJsonPath('data.counts.edc_to_check', 2)
            ->assertJsonPath('data.counts.expenses_due', 0)
            ->assertJsonPath('data.bell', 3)
            ->assertJsonPath('data.exchange_rate', 4100)
            ->assertJsonPath('data.reading.date', '2026-11-28')
            ->assertJsonPath('data.reading.days_left', 18)
            ->assertJsonPath('data.reading.rooms', 1);

        Carbon::setTestNow('2026-11-29');
        $this->getJson('/api/dashboard/badges')->assertJsonPath('data.reading.date', '2026-12-28');
    }

    public function test_only_the_owner_sees_the_money_overview(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));
        $this->getJson('/api/dashboard/overview')->assertForbidden();
    }
}
