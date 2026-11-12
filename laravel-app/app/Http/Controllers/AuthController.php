<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private const PENDING = 'two_factor_pending';

    // POST /api/login   { phone, password }   owner, staff and viewer
    public function login(Request $request)
    {
        $data = $request->validate([
            'phone'    => ['required', 'string', 'max:30'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $credentials = [
            'phone'     => User::normalizePhone($data['phone']),
            'password'  => $data['password'],
            'is_active' => true,
            fn ($query) => $query->whereIn('role', User::STAFF_ROLES),
        ];

        $guard = Auth::guard('web');

        if (! $guard->validate($credentials)) {
            // The same message whether the phone or the password is wrong
            throw ValidationException::withMessages(['phone' => 'The phone number or password is wrong.']);
        }

        $user = $guard->getLastAttempted();

        // Two-step login: remember who passed the password for 5 minutes, then ask for the code
        if ($user->hasTwoFactor()) {
            $request->session()->put(self::PENDING, ['id' => $user->id, 'until' => now()->addMinutes(5)->timestamp, 'tries' => 0]);

            return response()->json(['two_factor' => true]);
        }

        $guard->login($user);

        return $this->loggedIn($request, $user);
    }

    // POST /api/login/two-factor   { code } or { recovery_code }   the second step after the password
    public function loginTwoFactor(Request $request)
    {
        $data = $request->validate([
            'code'          => ['nullable', 'required_without:recovery_code', 'string', 'max:10'],
            'recovery_code' => ['nullable', 'string', 'max:20'],
        ]);

        $pending = $request->session()->get(self::PENDING);
        $user = $pending && $pending['until'] >= now()->timestamp
            ? User::where('id', $pending['id'])->where('is_active', true)->whereIn('role', User::STAFF_ROLES)->first()
            : null;

        if (! $user || ! $user->hasTwoFactor()) {
            $request->session()->forget(self::PENDING);
            throw ValidationException::withMessages(['code' => 'Please log in again with your phone number and password.']);
        }

        if (! TwoFactorController::check($user, $data['code'] ?? null, $data['recovery_code'] ?? null)) {
            $pending['tries']++;
            // After 5 wrong codes the password must be typed again
            $pending['tries'] >= 5
                ? $request->session()->forget(self::PENDING)
                : $request->session()->put(self::PENDING, $pending);

            throw ValidationException::withMessages(['code' => $pending['tries'] >= 5
                ? 'Too many wrong codes. Please log in again.'
                : 'The code is wrong or was already used.']);
        }

        $request->session()->forget(self::PENDING);
        Auth::guard('web')->login($user);

        return $this->loggedIn($request, $user);
    }

    // POST /api/login/qr   { token, phone }   tenants
    public function loginWithQr(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:30'],
        ]);

        $user = User::where('qr_token_hash', User::hashQrToken($data['token']))
            ->where('role', 'tenant')
            ->where('is_active', true)
            ->first();

        if ($user?->tenantAccessEnded()) {
            app(UserService::class)->disableTenantLogin($user->tenant);
            $user = null;
        }

        if (! $user) {
            throw ValidationException::withMessages(['token' => 'This QR code is no longer valid. Ask the owner for a new login card.']);
        }

        if ($user->qr_locked_at) {
            throw ValidationException::withMessages(['phone' => 'This login card is locked after too many wrong tries. Ask the owner for a new card.']);
        }

        // The phone number saved for this tenant
        $expected = User::normalizePhone((string) $user->tenant?->phone);

        if ($expected === '' || ! hash_equals($expected, User::normalizePhone($data['phone']))) {
            $attempts = $user->qr_failed_attempts + 1;
            $user->forceFill([
                'qr_failed_attempts' => $attempts,
                'qr_locked_at'       => $attempts >= User::MAX_QR_ATTEMPTS ? now() : null,
            ])->save();

            $left = User::MAX_QR_ATTEMPTS - $attempts;

            throw ValidationException::withMessages([
                'phone' => $left > 0
                    ? "The phone number is wrong. {$left} tries left."
                    : 'This login card is locked after too many wrong tries. Ask the owner for a new card.',
            ]);
        }

        $user->forceFill(['qr_failed_attempts' => 0])->save();
        Auth::guard('web')->login($user);

        return $this->loggedIn($request, $user);
    }

    // POST /api/logout
    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out.']);
    }

    // GET /api/me
    public function me(Request $request)
    {
        return response()->json(['data' => $this->present($request->user())]);
    }

    // PUT /api/me/password   { current_password, password, password_confirmation }
    public function changePassword(Request $request)
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'The current password is wrong.']);
        }

        $user->update(['password' => $data['password']]);

        return response()->json(['message' => 'Password changed.']);
    }

    private function loggedIn(Request $request, User $user)
    {
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json(['data' => $this->present($user)]);
    }

    private function present(User $user): array
    {
        $tenant = $user->isTenant() ? $user->tenant?->load('room') : null;

        return [
            'id'          => $user->id,
            'name'        => $user->name,
            'phone'       => $user->phone,
            'role'        => $user->role,
            'tenant_id'   => $user->tenant_id,
            'room_number' => $tenant?->room?->room_number,
            'two_factor'  => $user->hasTwoFactor(),
        ];
    }
}
