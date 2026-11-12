<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

// Owner only: logins for staff, viewers and tenants
class UserController extends Controller
{
    public function __construct(private UserService $userService)
    {
    }

    // GET /api/users   owner, staff and viewer logins
    public function index()
    {
        $users = User::whereIn('role', User::STAFF_ROLES)
            ->orderByDesc('is_active')
            ->orderByRaw("case role when 'owner' then 1 when 'staff' then 2 else 3 end")
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => $this->present($user));

        return response()->json(['data' => $users]);
    }

    // POST /api/users   { name, phone, role, password, password_confirmation }
    public function store(Request $request)
    {
        $request->merge(['phone' => User::normalizePhone((string) $request->input('phone'))]);

        $data = $request->validate([
            'name'     => ['required', 'string', 'max:100'],
            'phone'    => ['required', 'digits_between:8,15', Rule::unique('users', 'phone')],
            'role'     => ['required', Rule::in(User::STAFF_ROLES)],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], ['phone.unique' => 'This phone number already has a login.']);

        $user = User::create($data + ['is_active' => true]);

        return response()->json(['message' => 'Login created.', 'data' => $this->present($user)], 201);
    }

    // PATCH /api/users/{user}   { name, phone, role }
    public function update(Request $request, User $user)
    {
        $this->assertStaffLogin($user);
        $request->merge(['phone' => User::normalizePhone((string) $request->input('phone', $user->phone))]);

        $data = $request->validate([
            'name'  => ['required', 'string', 'max:100'],
            'phone' => ['required', 'digits_between:8,15', Rule::unique('users', 'phone')->ignore($user->id)],
            'role'  => ['required', Rule::in(User::STAFF_ROLES)],
        ], ['phone.unique' => 'This phone number already has a login.']);

        if ($request->user()->is($user) && $data['role'] !== 'owner') {
            throw ValidationException::withMessages(['role' => 'You cannot change your own role.']);
        }

        $user->update($data);

        return response()->json(['message' => 'Login updated.', 'data' => $this->present($user)]);
    }

    // POST /api/users/{user}/password   { password, password_confirmation }
    public function resetPassword(Request $request, User $user)
    {
        $this->assertStaffLogin($user);

        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        $user->update(['password' => $data['password']]);

        return response()->json(['message' => 'Password changed.', 'data' => $this->present($user)]);
    }

    // POST /api/users/{user}/disable
    public function disable(Request $request, User $user)
    {
        $this->userService->disable($user, $request->user());

        return response()->json(['message' => 'Login removed. This person is logged out everywhere.', 'data' => $this->present($user)]);
    }

    // POST /api/users/{user}/enable
    public function enable(User $user)
    {
        $this->userService->enable($user);

        return response()->json(['message' => 'Login allowed again.', 'data' => $this->present($user)]);
    }

    // GET /api/tenant-logins   tenants renting or moved out in the last 30 days, with their login status
    public function tenantLogins()
    {
        $logins = User::where('role', 'tenant')->get()->keyBy('tenant_id');
        $since = now()->subDays(User::TENANT_DAYS_AFTER_MOVE_OUT)->toDateString();

        $rows = Tenant::with('room')
            ->where(fn ($query) => $query->where('status', 'active')
                ->orWhere(fn ($moved) => $moved->where('status', 'moved_out')->whereDate('move_out_date', '>=', $since)))
            ->get()
            ->sortBy(fn (Tenant $tenant) => $tenant->room?->room_number)
            ->values()
            ->map(function (Tenant $tenant) use ($logins) {
                $login = $logins->get($tenant->id);

                return [
                    'tenant_id'     => $tenant->id,
                    'tenant_name'   => $tenant->full_name,
                    'room_number'   => $tenant->room?->room_number,
                    'phone'         => $tenant->phone,
                    'moved_out'     => $tenant->status === 'moved_out',
                    'access_until'  => $tenant->status === 'moved_out' && $tenant->move_out_date
                        ? $tenant->move_out_date->copy()->addDays(User::TENANT_DAYS_AFTER_MOVE_OUT)->toDateString()
                        : null,
                    'status'        => match (true) {
                        ! $login                => 'none',
                        ! $login->is_active     => 'removed',
                        $login->qr_locked_at !== null => 'locked',
                        default                 => 'active',
                    },
                    'user_id'       => $login?->id,
                    'last_login_at' => $login?->last_login_at?->toDateTimeString(),
                ];
            });

        return response()->json(['data' => $rows]);
    }

    // POST /api/tenants/{tenant}/login-card   new QR code, shown once
    public function issueTenantCard(Tenant $tenant)
    {
        $card = $this->userService->issueTenantCard($tenant->load('room'));

        return response()->json([
            'message' => 'New login card made. The old card no longer works.',
            'data'    => [
                'tenant_name' => $tenant->full_name,
                'room_number' => $tenant->room?->room_number,
                'token'       => $card['token'],
            ],
        ], 201);
    }

    // POST /api/tenants/{tenant}/login/disable
    public function disableTenantLogin(Request $request, Tenant $tenant)
    {
        $this->userService->disableTenantLogin($tenant, $request->user());

        return response()->json(['message' => 'The tenant login is removed.']);
    }

    private function assertStaffLogin(User $user): void
    {
        if ($user->isTenant()) {
            abort(404);
        }
    }

    private function present(User $user): array
    {
        return [
            'id'            => $user->id,
            'name'          => $user->name,
            'phone'         => $user->phone,
            'role'          => $user->role,
            'is_active'     => $user->is_active,
            'disabled_at'   => $user->disabled_at?->toDateTimeString(),
            'last_login_at' => $user->last_login_at?->toDateTimeString(),
            'two_factor'    => $user->hasTwoFactor(),
        ];
    }
}
