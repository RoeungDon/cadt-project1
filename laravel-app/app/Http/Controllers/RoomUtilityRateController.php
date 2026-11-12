<?php

namespace App\Http\Controllers;

use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoomUtilityRateController extends Controller
{
    // GET /api/room-utility-rates
    public function index()
    {
        return response()->json(['data' => $this->list()]);
    }

    // PUT /api/room-utility-rates
    public function update(Request $request)
    {
        $data = $request->validate([
            'rooms'                        => ['required', 'array', 'min:1'],
            'rooms.*.id'                   => ['required', 'integer', 'exists:rooms,id'],
            'rooms.*.electric_rate_type'   => ['nullable', Rule::in(Room::RATE_TYPES)],
            'rooms.*.electric_custom_rate' => ['nullable', 'integer', 'min:0', 'max:10000000',
                                               'required_with:rooms.*.electric_rate_type'],
            'rooms.*.water_rate_type'      => ['nullable', Rule::in(Room::RATE_TYPES)],
            'rooms.*.water_custom_rate'    => ['nullable', 'integer', 'min:0', 'max:10000000',
                                               'required_with:rooms.*.water_rate_type'],
        ], [
            'rooms.*.electric_custom_rate.required_with' => 'Enter the electricity rate.',
            'rooms.*.water_custom_rate.required_with'    => 'Enter the water rate.',
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['rooms'] as $row) {
                $electricType = $row['electric_rate_type'] ?? null;
                $waterType = $row['water_rate_type'] ?? null;

                // No type = use the tiered rates (method A or B)
                Room::whereKey($row['id'])->update([
                    'electric_rate_type'   => $electricType,
                    'electric_custom_rate' => $electricType ? $row['electric_custom_rate'] : null,
                    'water_rate_type'      => $waterType,
                    'water_custom_rate'    => $waterType ? $row['water_custom_rate'] : null,
                ]);
            }
        });

        return response()->json([
            'data'    => $this->list(),
            'message' => 'Room rates saved successfully.',
        ]);
    }

    private function list(): array
    {
        return Room::orderBy('room_number')
            ->get()
            ->map(fn (Room $room) => [
                'id'                   => $room->id,
                'room_number'          => $room->room_number,
                'floor'                => $room->floor,
                'status'               => $room->status,
                'electric_rate_type'   => $room->electric_rate_type,
                'electric_custom_rate' => $room->electric_custom_rate,
                'water_rate_type'      => $room->water_rate_type,
                'water_custom_rate'    => $room->water_custom_rate,
            ])
            ->all();
    }
}