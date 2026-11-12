<?php

namespace App\Http\Controllers;

use App\Models\MainMeter;
use App\Models\MainMeterReading;
use App\Models\Setting;
use App\Services\UtilityBalanceService;
use App\Services\UtilityTariff;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

// Main water and electricity meters (PPWSA, EDC) and how they compare with the room meters
class MainMeterController extends Controller
{
    public function __construct(private UtilityBalanceService $balance)
    {
    }

    // GET /api/main-meters?period=2026-11   money is shown to the owner only
    public function index(Request $request)
    {
        $data = $request->validate(['period' => ['nullable', 'date_format:Y-m']]);
        $period = $data['period'] ?? now()->format('Y-m');
        $settings = Setting::allValues();
        $isOwner = $request->user()->role === 'owner';

        $meters = MainMeter::orderBy('utility')->orderBy('id')->get()->map(function (MainMeter $meter) use ($period, $settings, $isOwner) {
            $balance = $this->balance->forPeriod($meter, $period, $settings);

            if (! $isOwner) {
                $balance = array_diff_key($balance, array_flip(['cost', 'collected', 'balance', 'charged_rate', 'next_unit_cost']));
                $balance['alerts'] = array_values(array_filter($balance['alerts'], fn ($alert) => in_array($alert['type'], ['no_previous', 'negative', 'unmetered'], true)));
            }

            return $meter->present() + ['balance' => $balance];
        });

        return response()->json(['data' => $meters, 'period' => $period]);
    }

    // POST /api/main-meters   owner
    public function store(Request $request)
    {
        $meter = MainMeter::create($this->validated($request));

        return response()->json(['message' => 'Main meter added.', 'data' => $meter->present()], 201);
    }

    // PUT /api/main-meters/{mainMeter}   owner (the utility cannot change once rooms are linked)
    public function update(Request $request, MainMeter $mainMeter)
    {
        $data = $this->validated($request);

        if ($data['utility'] !== $mainMeter->utility && $mainMeter->rooms()->exists()) {
            throw ValidationException::withMessages(['utility' => 'Unlink the rooms first.']);
        }

        $mainMeter->update($data);

        return response()->json(['message' => 'Main meter saved.', 'data' => $mainMeter->present()]);
    }

    // DELETE /api/main-meters/{mainMeter}   owner, only while it has no readings
    public function destroy(MainMeter $mainMeter)
    {
        if ($mainMeter->readings()->exists()) {
            throw ValidationException::withMessages(['meter' => 'This meter has readings and cannot be deleted.']);
        }

        $mainMeter->delete();

        return response()->json(['message' => 'Main meter deleted.']);
    }

    // POST /api/main-meters/{mainMeter}/readings   { period, reading, read_on, usage_override, note }   owner and staff
    public function saveReading(Request $request, MainMeter $mainMeter)
    {
        $data = $request->validate([
            'period'         => ['required', 'date_format:Y-m'],
            'reading'        => ['required', 'numeric', 'min:0', 'max:9999999999'],
            'read_on'        => ['required', 'date', 'before_or_equal:today'],
            'usage_override' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'note'           => ['nullable', 'string', 'max:300'],
        ]);

        // A smaller number than last month is only allowed when the supplier replaced the meter
        $previous = MainMeterReading::where('main_meter_id', $mainMeter->id)->where('period', UtilityBalanceService::previousPeriod($data['period']))->first();
        if ($previous && $data['reading'] < $previous->reading && ! isset($data['usage_override'])) {
            throw ValidationException::withMessages([
                'reading' => "Lower than last month ({$previous->reading}). If the meter was replaced, enter this month's usage as well.",
            ]);
        }

        $reading = MainMeterReading::updateOrCreate(
            ['main_meter_id' => $mainMeter->id, 'period' => $data['period']],
            $data + ['created_by' => $request->user()->id],
        );

        return response()->json([
            'message' => 'Main meter reading saved.',
            'data'    => $this->balance->forPeriod($mainMeter, $reading->period),
        ]);
    }

    // DELETE /api/main-meter-readings/{mainMeterReading}   owner
    public function destroyReading(MainMeterReading $mainMeterReading)
    {
        $mainMeterReading->delete();

        return response()->json(['message' => 'Reading deleted.']);
    }

    // GET /api/reports/utility-balance?from=2025-10&to=2026-10   owner
    public function report(Request $request)
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m'],
            'to'   => ['required', 'date_format:Y-m', 'after_or_equal:from'],
        ]);

        if (now()->createFromFormat('Y-m-d', "{$data['from']}-01")->diffInMonths(now()->createFromFormat('Y-m-d', "{$data['to']}-01")) > 36) {
            throw ValidationException::withMessages(['to' => 'Choose at most 36 months.']);
        }

        return response()->json(['data' => $this->balance->report($data['from'], $data['to'])]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'utility'       => ['required', Rule::in(MainMeter::UTILITIES)],
            'name'          => ['required', 'string', 'max:60'],
            'account'       => ['nullable', 'string', 'max:40'],
            'tier_mode'     => ['required', Rule::in(UtilityTariff::MODES)],
            'extra_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'monthly_fee'   => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'notes'         => ['nullable', 'string', 'max:500'],
        ]);

        return ['extra_percent' => $data['extra_percent'] ?? 0, 'monthly_fee' => $data['monthly_fee'] ?? 0] + $data;
    }
}
