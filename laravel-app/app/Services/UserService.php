<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserService
{
    /**
     * Remove someone from the system. They are logged out of every device at once,
     * their QR code stops working, and only an owner can let them back in.
     */
    public function disable(User $user, ?User $by = null): User
    {
        if ($by && $by->is($user)) {
            throw ValidationException::withMessages(['user' => 'You cannot remove your own login.']);
        }

        if ($user->role === 'owner' && User::where('role', 'owner')->where('is_active', true)->count() <= 1) {
            throw ValidationException::withMessages(['user' => 'The last owner cannot be removed.']);
        }

        DB::transaction(function () use ($user, $by) {
            $user->forceFill([
                'is_active'          => false,
                'disabled_at'        => now(),
                'disabled_by'        => $by?->id,
                'qr_token_hash'      => null,
                'qr_failed_attempts' => 0,
                'qr_locked_at'       => null,
                'remember_token'     => null,
            ])->save();

            $this->logOutEverywhere($user);
        });

        return $user;
    }

    // Only for owner, staff and viewer. A tenant gets a new login card instead.
    public function enable(User $user): User
    {
        if ($user->isTenant()) {
            throw ValidationException::withMessages(['user' => 'Issue a new login card for this tenant instead.']);
        }

        $user->forceFill(['is_active' => true, 'disabled_at' => null, 'disabled_by' => null])->save();

        return $user;
    }

    /**
     * A new QR code for a tenant. It is shown once only, and the old card stops working.
     *
     * @return array{user: User, token: string}
     */
    public function issueTenantCard(Tenant $tenant): array
    {
        if ($tenant->status !== 'active') {
            throw ValidationException::withMessages(['tenant' => 'Only a tenant who is still renting can have a login.']);
        }

        if (! User::normalizePhone((string) $tenant->phone)) {
            throw ValidationException::withMessages(['tenant' => 'Add the phone number of this tenant first: it is needed to log in.']);
        }

        $token = Str::random(48);

        $user = DB::transaction(function () use ($tenant, $token) {
            $user = User::firstOrNew(['tenant_id' => $tenant->id]);
            $user->fill([
                'name'      => $tenant->full_name,
                'role'      => 'tenant',
                'is_active' => true,
            ]);
            $user->password ??= Str::random(40); // tenants never log in with a password
            $user->forceFill([
                'qr_token_hash'      => User::hashQrToken($token),
                'qr_failed_attempts' => 0,
                'qr_locked_at'       => null,
                'disabled_at'        => null,
                'disabled_by'        => null,
            ])->save();

            $this->logOutEverywhere($user);

            return $user;
        });

        return ['user' => $user, 'token' => $token];
    }

    // Called when a tenant is deleted, and when the 30 days after moving out are over
    public function disableTenantLogin(Tenant $tenant, ?User $by = null): void
    {
        $user = User::where('tenant_id', $tenant->id)->where('is_active', true)->first();

        if ($user) {
            $this->disable($user, $by);
        }
    }

    private function logOutEverywhere(User $user): void
    {
        $user->tokens()->delete();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }
}
