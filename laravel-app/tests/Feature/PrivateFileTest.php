<?php

namespace Tests\Feature;

use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PrivateFileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    private function newTenant(): array
    {
        $room = Room::create(['room_number' => 'A1', 'floor' => 1, 'rent_price' => 100, 'status' => 'available']);

        return $this->post('/api/tenants', [
            'full_name' => 'Dara', 'phone' => '012345678', 'room_id' => $room->id, 'move_in_date' => '2026-10-01',
            'photo' => UploadedFile::fake()->image('face.jpg'),
            'documents' => [UploadedFile::fake()->create('ID card.pdf', 20, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');
    }

    public function test_tenant_files_are_private_and_open_only_with_a_signed_link(): void
    {
        $tenant = $this->newTenant();
        $saved = Tenant::with('documents')->find($tenant['id']);

        // Stored in storage/app/private, nothing in the public folder
        Storage::disk('local')->assertExists($saved->photo_path);
        Storage::disk('local')->assertExists($saved->documents[0]->path);
        Storage::disk('public')->assertMissing($saved->photo_path);
        Storage::disk('public')->assertMissing($saved->documents[0]->path);

        $url = $tenant['documents'][0]['url'];
        $this->assertStringContainsString('/api/files/', $url);
        $this->assertStringContainsString('signature=', $url);

        // The signed link opens without a login (an <img> or a new tab sends no token)
        auth()->forgetGuards();
        $this->app['auth']->guard('web')->logout();
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get($tenant['photo_url'])->assertOk();

        // Changed, missing or expired signature: refused
        $path = parse_url($url, PHP_URL_PATH);
        $this->get($path)->assertForbidden();
        $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden();
        $this->travel(121)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_the_old_public_link_no_longer_works_and_viewers_get_links_but_tenants_do_not(): void
    {
        $tenant = $this->newTenant();
        $path = Tenant::find($tenant['id'])->photo_path;
        $this->assertFalse(Storage::disk('public')->exists($path));

        Sanctum::actingAs(User::factory()->create(['role' => 'viewer']));
        $this->getJson("/api/tenants/{$tenant['id']}")->assertOk()->assertJsonPath('data.documents.0.name', 'ID card.pdf');

        // A tenant login cannot read the tenant list (and so never gets these links)
        Sanctum::actingAs(User::factory()->create(['role' => 'tenant', 'tenant_id' => $tenant['id'], 'phone' => null]));
        $this->getJson("/api/tenants/{$tenant['id']}")->assertForbidden();
    }

    public function test_the_migration_moves_old_public_files_to_private_storage(): void
    {
        $tenant = $this->newTenant();
        $saved = Tenant::with('documents')->find($tenant['id']);

        // Put the files back where the old version kept them
        foreach ([$saved->photo_path, $saved->documents[0]->path] as $path) {
            Storage::disk('public')->put($path, Storage::disk('local')->get($path));
            Storage::disk('local')->delete($path);
        }

        $migration = require database_path('migrations/2026_11_13_090000_move_tenant_files_to_private_storage.php');
        $migration->up();

        foreach ([$saved->photo_path, $saved->documents[0]->path] as $path) {
            Storage::disk('local')->assertExists($path);
            Storage::disk('public')->assertMissing($path);
        }

        $migration->up(); // running it again changes nothing
        Storage::disk('local')->assertExists($saved->photo_path);
    }
}
