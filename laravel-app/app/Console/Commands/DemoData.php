<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\MaintenanceRequest;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\TransientToken;
use RuntimeException;

/**
 * Fill an EMPTY database with made-up demo data: 6 rooms, 5 tenants, 4 months of meter readings and invoices,
 * payments, a KHQR receipt waiting, repairs, a move-out notice, main meters and expenses.
 * Every name, phone, ID and number is invented. Months are counted back from today.
 *
 *   php artisan migrate --force
 *   php artisan rtr:demo              (database must be empty)
 *   php artisan rtr:demo --fresh      (wipes the database first: migrate:fresh)
 *
 * Every step goes through the real API, so the demo follows the same rules as real use.
 * Refuses to run when APP_ENV=production.
 */
class DemoData extends Command
{
    protected $signature = 'rtr:demo {--fresh : Drop all tables and migrate again before filling}';

    protected $description = 'Fill an empty database with made-up demo data (not for production)';

    public const PASSWORD = 'Demo1234';

    private CarbonImmutable $month;

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refused: APP_ENV is production.');

            return 1;
        }

        if ($this->option('fresh')) {
            $this->call('migrate:fresh', ['--force' => true]);
        }

        if (User::exists() || Room::exists()) {
            $this->error('The database is not empty. Use --fresh to wipe it first.');

            return 1;
        }

        $this->month = CarbonImmutable::now()->startOfMonth();
        $cards = $this->fill();

        $this->info('Demo data is ready. Logins (password ' . self::PASSWORD . '):');
        $this->table(['Role', 'Phone'], [['owner', '010000001'], ['staff', '010000002'], ['viewer', '010000003']]);
        $this->line('Tenant portal (open the link, then type the phone):');
        $app = rtrim(array_values(config('cors.allowed_origins', []))[0] ?? 'http://localhost:5173', '/');
        foreach ($cards as $room => [$phone, $token]) {
            $this->line("  {$room}  {$phone}  {$app}/qr#{$token}");
        }

        return 0;
    }

    /** Month N back from this month (0 = this month), as Y-m */
    private function period(int $back): string
    {
        return $this->month->subMonths($back)->format('Y-m');
    }

    /** A day of month N back, never later than today */
    private function day(int $back, int $day): string
    {
        $date = $this->month->subMonths($back)->setDay($day);

        return $date->isFuture() ? CarbonImmutable::today()->toDateString() : $date->toDateString();
    }

    private function fill(): array
    {
        $owner = User::create(['name' => 'សុខ ដារ៉ា (Demo)', 'phone' => '010000001', 'password' => self::PASSWORD, 'role' => 'owner', 'is_active' => true]);
        $this->actAs($owner);

        $this->api('POST', 'users', ['name' => 'ចាន់ សុភា (Demo)', 'phone' => '010000002', 'role' => 'staff', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);
        $this->api('POST', 'users', ['name' => 'មាស ពិសី (Demo)', 'phone' => '010000003', 'role' => 'viewer', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);

        $this->api('PUT', 'settings', [
            'landlord_name' => 'សុខ ដារ៉ា', 'landlord_gender' => 'male', 'landlord_nationality' => 'ខ្មែរ',
            'landlord_id_card' => 'DEMO-000000', 'landlord_phone' => '010 000 001',
            'property_address' => 'ផ្ទះគំរូ ផ្លូវសាកល្បង សង្កាត់គំរូ ខណ្ឌគំរូ រាជធានីភ្នំពេញ',
            'payment_account' => 'DEMO OWNER · ABA 000 000 000', 'contract_term_months' => 12, 'payment_due_days' => 5,
        ]);
        $this->api('POST', 'settings/payment-qr', [], ['image' => $this->image('khqr.png')]);

        // Rooms D101–D106 (D = demo); D105 pays electricity to EDC itself
        $rooms = [];
        foreach ([['D101', 0, 120], ['D102', 0, 120], ['D103', 1, 150], ['D104', 1, 150], ['D105', 2, 150], ['D106', 2, 150]] as [$no, $floor, $rent]) {
            $data = ['room_number' => $no, 'floor' => $floor, 'type' => 'Single', 'rent_price' => $rent];
            if ($no === 'D105') {
                $data += ['electric_paid_to' => 'edc', 'edc_account' => 'DEMO-EDC-0001'];
            }
            $rooms[$no] = $this->api('POST', 'rooms', $data)['data']['id'];
        }

        $this->api('PUT', "rooms/{$rooms['D101']}/items", ['key_count' => 2, 'items' => [
            ['name' => 'គ្រែ', 'quantity' => 1, 'condition' => 'ល្អ'],
            ['name' => 'ពូក', 'quantity' => 1, 'condition' => 'ថ្មី'],
            ['name' => 'ទូខោអាវ', 'quantity' => 1, 'condition' => 'ល្អ'],
            ['name' => 'កង្ហារ', 'quantity' => 1, 'condition' => 'ល្អ', 'note' => 'កង្ហារពិដាន'],
            ['name' => 'ម៉ាស៊ីនត្រជាក់', 'quantity' => 1, 'condition' => 'ល្អ'],
            ['name' => 'វាំងនន', 'quantity' => 2, 'condition' => 'ល្អ'],
        ]]);
        $this->api('POST', "rooms/{$rooms['D101']}/items/copy", ['room_ids' => array_values(array_diff($rooms, [$rooms['D101']]))]);

        // Tenants (made-up names and phones); D106 stays empty
        $people = [
            'D101' => ['ស៊ុន សុខា', 'female', '010000101', 1200, 20],
            'D102' => ['កែវ ចន្ធូ', 'male', '010000102', 2300, 35],
            'D103' => ['លី ម៉េង', 'male', '010000103', 800, 12],
            'D104' => ['ហេង ស្រីនាង', 'female', '010000104', 1500, 28],
            'D105' => ['ពៅ វណ្ណា', 'male', '010000105', 640, 9],
        ];
        $moveIn = $this->day(4, 1);
        $tenants = [];
        foreach ($people as $no => [$name, $gender, $phone, $e, $w]) {
            $data = [
                'full_name' => $name, 'gender' => $gender, 'phone' => $phone, 'nationality' => 'ខ្មែរ',
                'id_card_number' => 'DEMO-' . substr($phone, -3), 'address' => 'ភូមិគំរូ ឃុំគំរូ ខេត្តគំរូ',
                'emergency_contact_name' => 'អ្នកទំនាក់ទំនងគំរូ', 'emergency_contact_phone' => '010000999',
                'room_id' => $rooms[$no], 'move_in_date' => $moveIn,
                'start_electric_meter' => $e, 'start_water_meter' => $w,
            ];
            if ($no === 'D101') {
                $data['occupants'] = [['full_name' => 'ស៊ុន ដាលីន', 'relationship' => 'ប្អូនស្រី']];
            }
            $tenants[$no] = $this->api('POST', 'tenants', $data)['data']['id'];

            $rent = Room::find($rooms[$no])->rent_price;
            $this->api('POST', "tenants/{$tenants[$no]}/move-in-payment", [
                'deposit_usd' => $rent, 'prepaid_usd' => $rent, 'paid_at' => $moveIn, 'method' => 'cash',
            ]);
        }

        // Meter readings and invoices for the last 4 months
        $use = ['D101' => [95, 110, 120, 128], 'D102' => [150, 170, 165, 180], 'D103' => [60, 70, 66, 72], 'D104' => [120, 115, 130, 260], 'D105' => [90, 95, 100, 98]];
        $water = ['D101' => 4, 'D102' => 6, 'D103' => 3, 'D104' => 5, 'D105' => 3];
        $meter = [];
        foreach ($people as $no => [, , , $e, $w]) {
            $meter[$no] = [$e, $w];
        }

        foreach ([4, 3, 2, 1] as $i => $back) {
            $period = $this->period($back);
            $last = $back === 1;
            $readings = [];
            foreach ($use as $no => $months) {
                $meter[$no][0] += $months[$i];
                $meter[$no][1] += $water[$no] + ($i % 2);
                $readings[] = ['room_id' => $rooms[$no], 'electric_current' => $meter[$no][0], 'water_current' => $meter[$no][1]];
            }
            $this->api('POST', 'meter-readings', ['period' => $period, 'readings' => $readings]);
            $this->api('POST', 'meter-readings/lock', ['period' => $period]);
            $made = $this->api('POST', 'invoices/generate', ['period' => $period, 'tenant_ids' => array_values($tenants)])['data'];

            foreach ($made as $row) {
                $invoice = Invoice::find($row['id']);
                $room = $invoice->tenant->room->room_number;

                // Everyone paid the older months; last month only D101 and D105 paid in full
                if (! $last || $room === 'D101' || $room === 'D105') {
                    $this->api('POST', "invoices/{$invoice->id}/payments", [
                        'paid_at' => $this->day($back - 1, 3), 'currency' => 'KHR',
                        'amount' => $invoice->balance_riel ?? $invoice->total_riel, 'method' => $room === 'D101' ? 'bank' : 'cash',
                    ]);
                }
                if ($last && $room === 'D103') {
                    $this->api('POST', "invoices/{$invoice->id}/payments", ['paid_at' => $this->day(0, 5), 'currency' => 'USD', 'amount' => 100, 'method' => 'cash']);
                }
                if ($last && $room === 'D104') {
                    $this->api('PATCH', "invoices/{$invoice->id}", ['due_date' => $this->month->setDay(6)->toDateString()]);
                }
            }
        }

        // Main meters (EDC and PPWSA) for the last two months
        $edc = $this->api('POST', 'main-meters', ['utility' => 'electric', 'name' => 'EDC main (demo)', 'account' => 'DEMO-EDC-MAIN', 'tier_mode' => 'whole'])['data'];
        $ppwsa = $this->api('POST', 'main-meters', ['utility' => 'water', 'name' => 'PPWSA main (demo)', 'account' => 'DEMO-W-MAIN', 'tier_mode' => 'progressive', 'extra_percent' => 10, 'monthly_fee' => 750])['data'];
        $this->api('POST', "main-meters/{$edc['id']}/readings", ['period' => $this->period(2), 'reading' => 20000, 'read_on' => $this->day(2, 28)]);
        $this->api('POST', "main-meters/{$edc['id']}/readings", ['period' => $this->period(1), 'reading' => 20720, 'read_on' => $this->day(1, 28)]);
        $this->api('POST', "main-meters/{$ppwsa['id']}/readings", ['period' => $this->period(2), 'reading' => 3000, 'read_on' => $this->day(2, 28)]);
        $this->api('POST', "main-meters/{$ppwsa['id']}/readings", ['period' => $this->period(1), 'reading' => 3031, 'read_on' => $this->day(1, 28)]);

        // Expenses
        $this->api('POST', 'recurring-expenses', ['category' => 'internet', 'name' => 'Internet (demo)', 'amount' => 15, 'currency' => 'USD', 'day_of_month' => 1, 'starts_on' => $this->day(2, 1)]);
        foreach ([2, 1] as $i => $back) {
            $this->api('POST', 'expenses', ['category' => 'electric', 'description' => 'វិក្កយបត្រ EDC (demo)', 'spent_on' => $this->day($back, 28), 'amount' => [512000, 548000][$i], 'currency' => 'KHR', 'status' => 'paid', 'paid_on' => $this->day($back - 1, 2), 'method' => 'bank']);
            $this->api('POST', 'expenses', ['category' => 'water', 'description' => 'វិក្កយបត្រទឹក PPWSA (demo)', 'spent_on' => $this->day($back, 28), 'amount' => [61000, 64500][$i], 'currency' => 'KHR', 'status' => 'paid', 'paid_on' => $this->day($back - 1, 2), 'method' => 'cash']);
            $this->api('POST', 'expenses', ['category' => 'garbage', 'description' => 'សេវាសំរាម (demo)', 'spent_on' => $this->day($back, 25), 'amount' => 20000, 'currency' => 'KHR', 'status' => 'paid', 'paid_on' => $this->day($back, 25), 'method' => 'cash']);
        }
        $this->api('POST', 'expenses', ['category' => 'supplies', 'description' => 'អំពូល LED ៥ គ្រាប់ (demo)', 'spent_on' => $this->day(0, 2), 'amount' => 7.5, 'currency' => 'USD', 'status' => 'due']);
        $this->api('POST', 'expenses', [
            'category' => 'repair', 'description' => 'ប្តូរសោទ្វារ (demo)', 'spent_on' => $this->day(0, 4), 'amount' => 12.5, 'currency' => 'USD',
            'status' => 'paid', 'paid_on' => $this->day(0, 4), 'method' => 'cash', 'room_id' => $rooms['D103'],
        ]);

        // Notice to tenants
        $this->api('POST', 'announcements', [
            'title' => 'បង្កើនការប្រុងប្រយ័ត្នចេញចូល', 'body' => 'សូមបិទទ្វាររបងពេលចេញចូល។ សូមអរគុណ',
            'starts_on' => $this->day(0, 1), 'ends_on' => $this->month->addMonths(2)->endOfMonth()->toDateString(),
        ]);

        // A repair the owner pays, done
        $done = $this->api('POST', 'maintenance-requests', ['tenant_id' => $tenants['D103'], 'title' => 'អំពូលបន្ទប់ទឹកខូច', 'priority' => 'normal'])['data'];
        $this->api('POST', "maintenance-requests/{$done['id']}/quote", ['materials_usd' => 2, 'labor_usd' => 0, 'paid_by' => 'owner']);
        $this->api('POST', "maintenance-requests/{$done['id']}/start");
        $this->api('POST', "maintenance-requests/{$done['id']}/complete");

        // Portal login cards for D102 and D104
        $cards = [];
        foreach (['D102', 'D104'] as $no) {
            $cards[$no] = [$people[$no][2], $this->api('POST', "tenants/{$tenants[$no]}/login-card")['data']['token']];
        }

        // D102 asks for a repair and sends an ABA receipt for last month
        $this->actAs(User::where('tenant_id', $tenants['D102'])->firstOrFail());
        $this->api('POST', 'portal/repairs', ['title' => 'ក្បាលរ៉ូប៊ីណេលេចទឹក', 'priority' => 'urgent', 'description' => 'លេចតាំងពីយប់មិញ'], ['photo' => $this->image('tap.png')]);
        $lastInvoice = Invoice::where('tenant_id', $tenants['D102'])->where('period', $this->period(1))->firstOrFail();
        $this->api('POST', "portal/invoices/{$lastInvoice->id}/proofs", [
            'currency' => 'KHR', 'amount' => $lastInvoice->total_riel, 'paid_on' => $this->day(0, 3), 'transaction_ref' => '000000000DEMO',
        ], ['image' => $this->image('receipt.png')]);

        // The owner prices that repair: the tenant pays it on the next invoice
        $this->actAs($owner);
        $repair = MaintenanceRequest::where('tenant_id', $tenants['D102'])->firstOrFail();
        $this->api('POST', "maintenance-requests/{$repair->id}/quote", ['materials_usd' => 3, 'labor_usd' => 2, 'paid_by' => 'tenant', 'charge_mode' => 'next_invoice', 'staff_note' => 'ប្តូរក្បាលរ៉ូប៊ីណេថ្មី']);

        // D104 gives notice to move out next month
        $this->actAs(User::where('tenant_id', $tenants['D104'])->firstOrFail());
        $this->api('POST', 'portal/notice', ['planned_move_out_date' => $this->month->addMonth()->setDay(20)->toDateString(), 'reason' => 'ប្តូរកន្លែងធ្វើការ']);

        // This month: three rooms read so far
        $this->actAs($owner);
        $this->api('POST', 'meter-readings', ['period' => $this->period(0), 'readings' => array_map(
            fn ($no) => ['room_id' => $rooms[$no], 'electric_current' => $meter[$no][0] + 40, 'water_current' => $meter[$no][1] + 2],
            ['D101', 'D102', 'D103'],
        )]);

        return $cards;
    }

    /** Make the next API calls as this user (the same way Sanctum does for a logged-in browser) */
    private function actAs(User $user): void
    {
        $user->withAccessToken(new TransientToken);
        app('auth')->guard('sanctum')->setUser($user);
        app('auth')->shouldUse('sanctum');
    }

    /** A copy of a demo picture, sent like a browser upload */
    private function image(string $name): UploadedFile
    {
        $copy = tempnam(sys_get_temp_dir(), 'demo');
        copy(database_path("demo/{$name}"), $copy);

        return new UploadedFile($copy, $name, 'image/png', null, true);
    }

    /** Send one request through the app; stop on any error */
    private function api(string $method, string $uri, array $data = [], array $files = []): array
    {
        $request = Request::create("/api/{$uri}", $method, $data, [], $files, ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1']);
        $response = app(HttpKernel::class)->handle($request);

        if ($response->getStatusCode() >= 300) {
            throw new RuntimeException("{$method} /api/{$uri} → {$response->getStatusCode()}: " . mb_substr($response->getContent(), 0, 500));
        }

        return json_decode($response->getContent(), true) ?? [];
    }
}
