<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\InvoiceService;
use App\Services\MeterReadingService;
use App\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

// Meters are read on the 28th and invoices are made on the 30th (the last day of February)
class BillingDaysTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $room, string $moveIn): Tenant
    {
        $room = Room::create(['room_number' => $room, 'floor' => 1, 'rent_price' => 120, 'status' => 'available']);

        return app(TenantService::class)->create([
            'full_name'            => "Tenant {$room->room_number}",
            'phone'                => '012000000',
            'room_id'              => $room->id,
            'move_in_date'         => $moveIn,
            'start_electric_meter' => 100,
            'start_water_meter'    => 10,
        ]);
    }

    public function test_the_reading_and_invoice_days_of_each_month(): void
    {
        $this->assertSame('2026-11-28', InvoiceService::readingDate('2026-11')->toDateString());
        $this->assertSame('2026-11-30', InvoiceService::invoiceDate('2026-11')->toDateString());
        $this->assertSame('2026-12-30', InvoiceService::invoiceDate('2026-12')->toDateString());
        $this->assertSame('2027-02-28', InvoiceService::invoiceDate('2027-02')->toDateString());
        $this->assertSame('2028-02-29', InvoiceService::invoiceDate('2028-02')->toDateString());
    }

    public function test_a_tenant_who_moves_in_on_or_after_the_28th_pays_only_next_months_rent(): void
    {
        $before = $this->tenant('A1', '2026-11-27');
        $onDay = $this->tenant('A2', '2026-11-28');
        $after = $this->tenant('A3', '2026-11-29');

        $rows = collect($this->getJson('/api/invoices/preview?period=2026-11')->assertOk()->json('data'))->keyBy('tenant_id');

        $this->assertSame('missing_reading', $rows[$before->id]['status']); // was in the room on the 28th: read the meter
        $this->assertSame('rent_only', $rows[$onDay->id]['status']);
        $this->assertSame('rent_only', $rows[$after->id]['status']);
        $this->assertEqualsWithDelta(120, $rows[$after->id]['rent_usd'], 0.001); // all of December

        // The meter page says there is nothing to read for them this month
        $sheet = collect($this->getJson('/api/meter-readings?period=2026-11')->assertOk()->json('data'))->keyBy('room_number');
        $this->assertFalse($sheet['A1']['after_reading_day']);
        $this->assertTrue($sheet['A3']['after_reading_day']);

        // No fake 0 reading is needed to make their first invoice
        $this->postJson('/api/invoices/generate', ['period' => '2026-11', 'tenant_ids' => [$after->id], 'issue_date' => '2026-11-30'])
            ->assertSuccessful();
        $invoice = Invoice::where('tenant_id', $after->id)->firstOrFail();
        $this->assertSame('2026-12', $invoice->rent_period);
        $this->assertSame(0, (int) $invoice->electric_amount + (int) $invoice->water_amount);
    }

    public function test_the_menu_box_shows_the_next_invoice_day_and_a_late_month(): void
    {
        $tenant = $this->tenant('A1', '2026-10-01');

        Carbon::setTestNow('2026-11-21');
        $this->getJson('/api/dashboard/badges')->assertOk()
            ->assertJsonPath('data.invoice.date', '2026-11-30')
            ->assertJsonPath('data.invoice.days_left', 9)
            ->assertJsonPath('data.invoice.late', false)
            ->assertJsonPath('data.invoice.done', 0)
            ->assertJsonPath('data.invoice.tenants', 1);

        // 3 December and November is not invoiced yet: late, and the bell rings
        Carbon::setTestNow('2026-12-03');
        $bell = $this->getJson('/api/dashboard/badges')->assertOk()
            ->assertJsonPath('data.invoice.period', '2026-11')
            ->assertJsonPath('data.invoice.late', true)
            ->assertJsonPath('data.invoice.days_late', 3)
            ->json('data.bell');
        $this->assertGreaterThanOrEqual(1, $bell);

        // Once November is read and invoiced the box moves on to 30 December
        app(MeterReadingService::class)->saveForTenant($tenant->room->fresh(), $tenant->fresh(), '2026-11', ['electric' => 110, 'water' => 12]);
        $this->postJson('/api/invoices/generate', ['period' => '2026-11', 'tenant_ids' => [$tenant->id], 'issue_date' => '2026-11-30'])
            ->assertSuccessful();
        $this->getJson('/api/dashboard/badges')
            ->assertJsonPath('data.invoice.date', '2026-12-30')
            ->assertJsonPath('data.invoice.late', false);

        // February has no 30th
        Carbon::setTestNow('2027-02-10');
        $this->getJson('/api/dashboard/badges')->assertJsonPath('data.invoice.date', '2027-02-28');
    }
}
