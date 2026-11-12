<?php

namespace Tests\Feature;

use App\Models\PaymentProof;
use App\Models\Room;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PaymentProofTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        $this->owner = User::factory()->create(['role' => 'owner']);
        Sanctum::actingAs($this->owner);
    }

    private function tenantWithInvoice(string $room): array
    {
        $room = Room::create(['room_number' => $room, 'floor' => 1, 'rent_price' => 100, 'status' => 'available']);
        $tenant = Tenant::create([
            'full_name' => "Tenant {$room->room_number}", 'phone' => '011', 'room_id' => $room->id, 'status' => 'active',
            'move_in_date' => '2026-10-01', 'start_electric_meter' => 100, 'start_water_meter' => 10,
        ]);
        $this->postJson('/api/meter-readings', ['period' => '2026-10', 'readings' => [['room_id' => $room->id, 'electric_current' => 120, 'water_current' => 12]]])->assertSuccessful();
        $invoice = $this->postJson('/api/invoices/generate', ['period' => '2026-10', 'tenant_ids' => [$tenant->id]])->assertCreated()->json('data.0');

        return [$tenant, $invoice];
    }

    private function asTenant(Tenant $tenant): void
    {
        Sanctum::actingAs(User::where('tenant_id', $tenant->id)->first()
            ?? User::factory()->create(['role' => 'tenant', 'tenant_id' => $tenant->id, 'phone' => null]));
    }

    // A fake picture; the same size gives the same file (and the same SHA-256)
    private function send(int $invoiceId, int $size, array $extra = [])
    {
        return $this->post("/api/portal/invoices/{$invoiceId}/proofs", $extra + [
            'image' => UploadedFile::fake()->image('aba.jpg', $size, $size),
            'currency' => 'KHR', 'amount' => 100000, 'paid_on' => now()->toDateString(),
        ], ['Accept' => 'application/json']);
    }

    public function test_the_owner_sets_the_khqr_and_tenants_see_it(): void
    {
        $this->post('/api/settings/payment-qr', ['image' => UploadedFile::fake()->image('qr.png', 300, 300)], ['Accept' => 'application/json'])->assertOk();
        $this->putJson('/api/settings', ['payment_account' => 'Demo Owner · ABA 000 123 456'])->assertOk();
        $this->assertNotNull($this->getJson('/api/settings')->json('data.payment_qr_url'));

        Sanctum::actingAs(User::factory()->create(['role' => 'staff']));
        $this->post('/api/settings/payment-qr', ['image' => UploadedFile::fake()->image('qr.png')], ['Accept' => 'application/json'])->assertForbidden();

        [$tenant] = $this->tenantWithInvoice('A1');
        $this->asTenant($tenant);
        $payment = $this->getJson('/api/portal/home')->json('data.payment');
        $this->assertNotNull($payment['qr_url']);
        $this->assertSame('Demo Owner · ABA 000 123 456', $payment['account']);
    }

    public function test_a_receipt_is_confirmed_once_and_never_for_more_than_the_balance(): void
    {
        [$tenant, $invoice] = $this->tenantWithInvoice('A1');

        $this->asTenant($tenant);
        $proof = $this->send($invoice['id'], 50, ['transaction_ref' => 'abc-123 45'])->assertCreated()->json('data');
        $this->assertSame(['pending', 'ABC12345'], [$proof['status'], $proof['transaction_ref']]);
        $this->get($proof['image_url'])->assertOk();

        // Viewers never see receipts (they show bank details)
        Sanctum::actingAs(User::factory()->create(['role' => 'viewer']));
        $this->getJson('/api/payment-proofs')->assertForbidden();

        $staff = User::factory()->create(['role' => 'staff']);
        Sanctum::actingAs($staff);
        $this->getJson('/api/payment-proofs')->assertOk()->assertJsonPath('pending', 1)->assertJsonPath('data.0.tenant_name', 'Tenant A1');
        $this->get("/api/payment-proofs/{$proof['id']}/image")->assertOk();

        // More than the balance is refused
        $this->postJson("/api/payment-proofs/{$proof['id']}/confirm", ['amount' => $invoice['total_riel'] + 100])->assertStatus(422);

        $this->postJson("/api/payment-proofs/{$proof['id']}/confirm")->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->postJson("/api/payment-proofs/{$proof['id']}/confirm")->assertStatus(422); // only once

        $saved = $this->getJson("/api/invoices/{$invoice['id']}")->json('data');
        $this->assertSame(100000, $saved['paid_riel']);
        $this->assertSame('bank', $saved['payments'][0]['method']);
        $this->assertStringContainsString("KHQR #{$proof['id']} ABC12345", $saved['payments'][0]['reference']);

        // Removing the payment (owner) puts the receipt back to pending
        Sanctum::actingAs($this->owner);
        $this->deleteJson("/api/invoices/{$invoice['id']}/payments/{$saved['payments'][0]['id']}")->assertOk();
        $this->assertSame('pending', PaymentProof::find($proof['id'])->status);
    }

    public function test_the_same_picture_or_aba_number_cannot_be_sent_twice(): void
    {
        [$tenant, $invoice] = $this->tenantWithInvoice('A1');
        $this->asTenant($tenant);

        $first = $this->send($invoice['id'], 50, ['transaction_ref' => 'TRX-1'])->assertCreated()->json('data');
        $this->send($invoice['id'], 50)->assertStatus(422)->assertJsonValidationErrors('image');                       // same file
        $this->send($invoice['id'], 60, ['transaction_ref' => 'trx 1'])->assertStatus(422)->assertJsonValidationErrors('transaction_ref'); // same ABA number
        $this->send($invoice['id'], 60)->assertCreated();                                                               // a different receipt

        // Rejected: the tenant may send the same picture again
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/payment-proofs/{$first['id']}/reject", ['reason' => 'Amount does not match'])->assertOk();
        $this->asTenant($tenant);
        $this->getJson('/api/portal/proofs')->assertJsonPath('data.1.reject_reason', 'Amount does not match');
        $this->send($invoice['id'], 50, ['transaction_ref' => 'TRX-1'])->assertCreated();

        // At most 3 waiting per invoice
        $this->send($invoice['id'], 70)->assertCreated();
        $this->send($invoice['id'], 80)->assertStatus(422);
    }

    public function test_a_tenant_cannot_see_or_pay_another_tenants_invoice(): void
    {
        [$mine, $myInvoice] = $this->tenantWithInvoice('A1');
        [$other, $otherInvoice] = $this->tenantWithInvoice('A2');

        $this->asTenant($other);
        $otherProof = $this->send($otherInvoice['id'], 50)->assertCreated()->json('data');

        $this->asTenant($mine);
        $this->send($otherInvoice['id'], 60)->assertNotFound();
        $this->get($otherProof['image_url'])->assertNotFound();
        $this->getJson('/api/portal/proofs')->assertJsonCount(0, 'data');
    }
}
