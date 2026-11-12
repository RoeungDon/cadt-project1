<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected bool $actAsOwner = false;

    private function fromApp(): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost:5173', 'Referer' => 'http://localhost:5173/']);
    }

    // Turn two-step login on for this user and return [secret, recovery codes]
    private function turnOn(User $user): array
    {
        Sanctum::actingAs($user);
        $start = $this->postJson('/api/me/two-factor', ['password' => 'secret123'])->assertOk()->json('data');
        $secret = str_replace(' ', '', $start['secret']);
        $this->assertStringStartsWith('otpauth://totp/', $start['uri']);

        $this->postJson('/api/me/two-factor/confirm', ['code' => '000000'])->assertStatus(422);
        $codes = $this->postJson('/api/me/two-factor/confirm', ['code' => Totp::code($secret, intdiv(time(), 30))])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonCount(10, 'data.recovery_codes')
            ->json('data.recovery_codes');

        $this->app['auth']->forgetGuards();

        return [$secret, $codes];
    }

    public function test_the_secret_is_turned_on_with_the_first_code_and_the_rfc_numbers_match(): void
    {
        // RFC 6238 test numbers (secret "12345678901234567890")
        $this->assertSame('287082', Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1));
        $this->assertSame('081804', Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 37037036));

        $user = User::factory()->create(['phone' => '012345678', 'password' => 'secret123', 'role' => 'staff']);
        $this->turnOn($user);

        $user->refresh();
        $this->assertTrue($user->hasTwoFactor());
        // Kept encrypted, never sent back
        $this->assertStringNotContainsString($user->two_factor_secret, (string) \DB::table('users')->where('id', $user->id)->value('two_factor_secret'));
        $this->assertArrayNotHasKey('two_factor_secret', $user->toArray());
    }

    public function test_the_login_asks_for_the_code_and_each_code_works_once(): void
    {
        // Login and code share the limit of 5 tries a minute; this test makes more
        $this->withoutMiddleware(ThrottleRequests::class);

        $user = User::factory()->create(['phone' => '012345678', 'password' => 'secret123', 'role' => 'owner']);
        [$secret, $codes] = $this->turnOn($user);
        // The confirm used the code of this 30 seconds; the login needs the next one
        $next = Totp::code($secret, intdiv(time(), 30) + 1);

        $this->fromApp()->postJson('/api/login', ['phone' => '012345678', 'password' => 'secret123'])
            ->assertOk()->assertJsonPath('two_factor', true);
        $this->getJson('/api/me')->assertUnauthorized();

        $this->fromApp()->postJson('/api/login/two-factor', ['code' => '111111'])->assertStatus(422);
        $this->fromApp()->postJson('/api/login/two-factor', ['code' => $next])
            ->assertOk()->assertJsonPath('data.two_factor', true);
        $this->getJson('/api/me')->assertOk();

        // The same code again, after logging out and in, is refused
        $this->fromApp()->postJson('/api/logout');
        $this->app['auth']->forgetGuards();
        $this->fromApp()->postJson('/api/login', ['phone' => '012345678', 'password' => 'secret123'])->assertOk();
        $this->fromApp()->postJson('/api/login/two-factor', ['code' => $next])->assertStatus(422);

        // A recovery code works once
        $this->fromApp()->postJson('/api/login/two-factor', ['recovery_code' => strtolower($codes[0])])->assertOk();
        $this->assertCount(9, $user->refresh()->two_factor_recovery_codes);
    }

    public function test_no_code_step_without_the_password_and_the_owner_can_reset(): void
    {
        $this->fromApp()->postJson('/api/login/two-factor', ['code' => '123456'])->assertStatus(422);

        $owner = User::factory()->create(['password' => 'secret123', 'role' => 'owner']);
        $staff = User::factory()->create(['password' => 'secret123', 'role' => 'staff']);
        $this->turnOn($staff);

        Sanctum::actingAs($owner);
        $this->getJson('/api/users')->assertOk();
        $this->postJson("/api/users/{$staff->id}/two-factor/reset")->assertOk();
        $this->assertFalse($staff->refresh()->hasTwoFactor());

        Sanctum::actingAs($staff);
        $this->postJson("/api/users/{$owner->id}/two-factor/reset")->assertForbidden();
    }
}
