<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * owner:  everything, including voiding, deleting, deposits, settings and logins
     * staff:  meter readings, tenants, invoices and receiving payments
     * viewer: read only
     * tenant: only their own room, invoices and requests (QR code + phone number)
     */
    public const ROLES = ['owner', 'staff', 'viewer', 'tenant'];

    public const STAFF_ROLES = ['owner', 'staff', 'viewer'];

    public const MAX_QR_ATTEMPTS = 5;

    // A tenant who moved out can still look at their invoices and deposit for this many days
    public const TENANT_DAYS_AFTER_MOVE_OUT = 30;

    protected $fillable = ['name', 'phone', 'email', 'password', 'role', 'tenant_id', 'is_active'];

    protected $hidden = ['password', 'remember_token', 'qr_token_hash', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'disabled_at'       => 'datetime',
            'qr_locked_at'      => 'datetime',
            'is_active'         => 'boolean',
            'password'          => 'hashed',
            'two_factor_secret'         => 'encrypted',
            'two_factor_recovery_codes' => 'array',
            'two_factor_confirmed_at'   => 'datetime',
            'two_factor_last_step'      => 'integer',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withTrashed();
    }

    // Two-step login is on once the first code from the app was accepted
    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    public function isTenant(): bool
    {
        return $this->role === 'tenant';
    }

    // A tenant login ends when the tenant is deleted, or 30 days after moving out
    public function tenantAccessEnded(): bool
    {
        if (! $this->isTenant()) {
            return false;
        }

        $tenant = $this->tenant;

        if (! $tenant || $tenant->trashed()) {
            return true;
        }

        return $tenant->status === 'moved_out'
            && $tenant->move_out_date
            && $tenant->move_out_date->copy()->addDays(self::TENANT_DAYS_AFTER_MOVE_OUT)->endOfDay()->isPast();
    }

    // "012 345-678", "+855 12 345 678" and "12345678" all become "012345678"
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone);

        if (str_starts_with($digits, '855') && strlen($digits) > 9) {
            $digits = substr($digits, 3);
        }

        return $digits === '' || str_starts_with($digits, '0') ? $digits : '0' . $digits;
    }

    public static function hashQrToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
