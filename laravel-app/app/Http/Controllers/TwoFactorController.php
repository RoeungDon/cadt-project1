<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Two-step login: after the password, a 6-digit code from an Authenticator app on the phone.
 * Ten recovery codes, each usable once, open the account if the phone is lost.
 */
class TwoFactorController extends Controller
{
    public const RECOVERY_CODES = 10;

    // GET /api/me/two-factor
    public function show(Request $request)
    {
        return response()->json(['data' => $this->status($request->user())]);
    }

    // POST /api/me/two-factor   { password }   a new secret, shown once as a QR code
    public function start(Request $request)
    {
        $user = $request->user();
        $this->checkPassword($request, $user);

        if ($user->hasTwoFactor()) {
            throw ValidationException::withMessages(['password' => 'Two-step login is already on. Turn it off first.']);
        }

        $secret = Totp::newSecret();
        $user->forceFill([
            'two_factor_secret'         => $secret,
            'two_factor_confirmed_at'   => null,
            'two_factor_recovery_codes' => null,
            'two_factor_last_step'      => null,
        ])->save();

        return response()->json(['data' => [
            'secret' => trim(chunk_split($secret, 4, ' ')),
            'uri'    => Totp::uri($secret, $user->phone ?: $user->name),
        ]]);
    }

    // POST /api/me/two-factor/confirm   { code }   the first code from the app turns it on
    public function confirm(Request $request)
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $user = $request->user();

        if (! $user->two_factor_secret || $user->hasTwoFactor()) {
            throw ValidationException::withMessages(['code' => 'Start again: scan a new QR code first.']);
        }

        $step = Totp::verify($user->two_factor_secret, $data['code']);
        if ($step === null) {
            throw ValidationException::withMessages(['code' => 'The code is wrong. Check the time on the phone and try the newest code.']);
        }

        $codes = $this->newRecoveryCodes($user);
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_last_step' => $step])->save();

        return response()->json([
            'message' => 'Two-step login is on.',
            'data'    => $this->status($user) + ['recovery_codes' => $codes],
        ]);
    }

    // POST /api/me/two-factor/recovery-codes   { password }   ten new codes; the old ones stop working
    public function recoveryCodes(Request $request)
    {
        $user = $request->user();
        $this->checkPassword($request, $user);

        if (! $user->hasTwoFactor()) {
            throw ValidationException::withMessages(['password' => 'Two-step login is off.']);
        }

        return response()->json(['data' => $this->status($user) + ['recovery_codes' => $this->newRecoveryCodes($user)]]);
    }

    // DELETE /api/me/two-factor   { password }
    public function destroy(Request $request)
    {
        $user = $request->user();
        $this->checkPassword($request, $user);
        $this->turnOff($user);

        return response()->json(['message' => 'Two-step login is off.', 'data' => $this->status($user)]);
    }

    // POST /api/users/{user}/two-factor/reset   the owner helps someone who lost the phone and the codes
    public function reset(Request $request, User $user)
    {
        if ($user->is($request->user())) {
            throw ValidationException::withMessages(['user' => 'Turn off your own two-step login in Account security.']);
        }

        $this->turnOff($user);

        return response()->json(['message' => 'Two-step login is off for this person. They can turn it on again.']);
    }

    /**
     * Used by the login: a 6-digit code (each only once) or one recovery code.
     */
    public static function check(User $user, ?string $code, ?string $recovery): bool
    {
        if ($code) {
            $step = Totp::verify($user->two_factor_secret, $code, $user->two_factor_last_step);
            if ($step !== null) {
                $user->forceFill(['two_factor_last_step' => $step])->save();

                return true;
            }

            return false;
        }

        if ($recovery) {
            $hash = self::hashCode($recovery);
            $codes = $user->two_factor_recovery_codes ?? [];
            $index = array_search($hash, $codes, true);

            if ($index !== false) {
                unset($codes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    private static function hashCode(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)));
    }

    private function newRecoveryCodes(User $user): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $raw = strtoupper(Str::random(10));
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5);
        }

        $user->forceFill(['two_factor_recovery_codes' => array_map([self::class, 'hashCode'], $codes)])->save();

        return $codes;
    }

    private function turnOff(User $user): void
    {
        $user->forceFill([
            'two_factor_secret'         => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at'   => null,
            'two_factor_last_step'      => null,
        ])->save();
    }

    private function checkPassword(Request $request, User $user): void
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:255']]);

        if (! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['password' => 'The password is wrong.']);
        }
    }

    private function status(User $user): array
    {
        return [
            'enabled'        => $user->hasTwoFactor(),
            'confirmed_at'   => $user->two_factor_confirmed_at?->toDateTimeString(),
            'recovery_left'  => $user->hasTwoFactor() ? count($user->two_factor_recovery_codes ?? []) : 0,
        ];
    }
}
