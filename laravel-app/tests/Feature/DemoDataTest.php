<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PaymentProof;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    protected bool $actAsOwner = false;

    public function test_the_demo_fills_an_empty_database_and_refuses_a_second_time(): void
    {
        Storage::fake('local');

        $this->artisan('rtr:demo')->assertExitCode(0);

        $this->assertSame(6, Room::count());
        $this->assertSame(5, Tenant::count());
        $this->assertSame(20, Invoice::count());
        $this->assertSame(1, PaymentProof::count());
        $this->assertSame(['owner', 'staff', 'viewer'], User::whereNull('tenant_id')->orderBy('phone')->pluck('role')->all());
        $this->assertSame(0, Tenant::where('phone', 'not like', '0100001%')->count());

        $this->artisan('rtr:demo')->assertExitCode(1);
    }
}
