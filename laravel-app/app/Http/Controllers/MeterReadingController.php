<?php

namespace App\Http\Controllers;

use App\Models\MeterReading;
use App\Models\MeterReplacement;
use App\Models\Room;
use App\Models\Setting;
use App\Services\MeterReadingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeterReadingController extends Controller
{
    public function __construct(private MeterReadingService $meterReadingService)
    {
    }

    // GET /api/meter-readings?period=2026-11
    public function index(Request $request)
    {
        $data = $request->validate([
            'period' => ['nullable', 'date_format:Y-m'],
        ]);

        $period = $data['period'] ?? now()->format('Y-m');

        return $this->sheetResponse($period);
    }

    // POST /api/meter-readings
    public function store(Request $request)
    {
        $data = $request->validate([
            'period'                      => ['required', 'date_format:Y-m'],
            'read_at'                     => ['nullable', 'date'],
            'readings'                    => ['required', 'array', 'min:1'],
            'readings.*.room_id'          => ['required', 'integer', 'exists:rooms,id'],
            'readings.*.electric_current' => ['nullable', 'numeric', 'min:0'],
            'readings.*.water_current'    => ['nullable', 'numeric', 'min:0'],
            'readings.*.notes'            => ['nullable', 'string', 'max:500'],
        ]);

        $count = $this->meterReadingService->saveMany($data['period'], $data['readings'], $data['read_at'] ?? null);

        return $this->sheetResponse($data['period'], $count === 1 ? '1 room saved.' : "{$count} rooms saved.");
    }

    // DELETE /api/meter-readings/{meterReading}
    public function destroy(MeterReading $meterReading)
    {
        $this->meterReadingService->deleteReading($meterReading);

        return response()->json(['message' => 'Reading deleted.']);
    }

    // POST /api/meter-readings/{meterReading}/photo   (form fields: utility, photo)
    public function uploadPhoto(Request $request, MeterReading $meterReading)
    {
        $data = $request->validate([
            'utility' => ['required', Rule::in(MeterReadingService::UTILITIES)],
            'photo'   => ['required', 'image', 'max:5120'], // 5 MB
        ]);

        $url = $this->meterReadingService->savePhoto($meterReading, $data['utility'], $request->file('photo'));

        return response()->json([
            'photo_url' => $url,
            'message'   => 'Photo saved.',
        ]);
    }

    // POST /api/meter-readings/lock   { "period": "2026-11" }
    public function lock(Request $request)
    {
        $data = $request->validate(['period' => ['required', 'date_format:Y-m']]);
        $count = $this->meterReadingService->setMonthLock($data['period'], true);

        return $this->sheetResponse($data['period'], "{$count} reading(s) locked.");
    }

    // POST /api/meter-readings/unlock   { "period": "2026-11" }
    public function unlock(Request $request)
    {
        $data = $request->validate(['period' => ['required', 'date_format:Y-m']]);
        $count = $this->meterReadingService->setMonthLock($data['period'], false);

        return $this->sheetResponse($data['period'], "{$count} reading(s) unlocked.");
    }

    // POST /api/meter-replacements
    public function storeReplacement(Request $request)
    {
        $data = $request->validate([
            'room_id'         => ['required', 'integer', 'exists:rooms,id'],
            'utility'         => ['required', Rule::in(MeterReplacement::UTILITIES)],
            'period'          => ['required', 'date_format:Y-m'],
            'old_meter_final' => ['required', 'numeric', 'min:0'],
            'new_meter_start' => ['nullable', 'numeric', 'min:0'],
            'replaced_at'     => ['nullable', 'date'],
            'notes'           => ['nullable', 'string', 'max:500'],
        ]);

        $room = Room::with('activeTenant')->findOrFail($data['room_id']);
        $this->meterReadingService->recordReplacement($room, $data);

        return $this->sheetResponse($data['period'], 'Meter replacement saved. Enter this month\'s reading of the new meter.');
    }

    // DELETE /api/meter-replacements/{meterReplacement}
    public function destroyReplacement(MeterReplacement $meterReplacement)
    {
        $period = $meterReplacement->period;
        $this->meterReadingService->deleteReplacement($meterReplacement);

        return $this->sheetResponse($period, 'Meter replacement removed. Enter this month\'s reading again.');
    }

    private function sheetResponse(string $period, ?string $message = null)
    {
        return response()->json(array_filter([
            'message' => $message,
            'data'    => $this->meterReadingService->sheet($period),
            'meta'    => [
                'period'   => $period,
                'settings' => Setting::allValues(),
            ],
        ], fn ($value) => $value !== null));
    }
}