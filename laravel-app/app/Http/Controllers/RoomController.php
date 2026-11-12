<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreRoomRequest;
use App\Http\Requests\UpdateRoomRequest;
use App\Http\Resources\RoomResource;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoomController extends Controller
{
    // GET /api/rooms?search=A1&status=available&per_page=10
    public function index(Request $request)
    {
        $rooms = Room::query()
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('room_number', 'like', "%{$search}%")
                      ->orWhere('type', 'like', "%{$search}%");
                });
            })
            ->when($request->status, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('room_number')
            ->paginate($request->integer('per_page', 10));

        return RoomResource::collection($rooms);
    }

    // POST /api/rooms
    public function store(StoreRoomRequest $request)
    {
        $room = Room::create($request->validated());

        return (new RoomResource($room))
            ->additional(['message' => 'Room created successfully.'])
            ->response()
            ->setStatusCode(201);
    }

    // GET /api/rooms/{room}
    public function show(Room $room)
    {
        return new RoomResource($room);
    }

    // PUT /api/rooms/{room}
    public function update(UpdateRoomRequest $request, Room $room)
    {
        $room->update($request->validated());

        return (new RoomResource($room))
            ->additional(['message' => 'Room updated successfully.']);
    }

    // PATCH /api/rooms/{room}/status
    public function updateStatus(Request $request, Room $room)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(Room::STATUSES)],
        ]);

        $room->update($data);

        return (new RoomResource($room))
            ->additional(['message' => 'Room status updated.']);
    }

    // DELETE /api/rooms/{room}
    public function destroy(Room $room)
    {
        if ($room->status === 'occupied') {
            return response()->json([
                'message' => 'Cannot delete an occupied room.',
            ], 422);
        }

        $room->delete();

        return response()->json(['message' => 'Room deleted successfully.']);
    }
}