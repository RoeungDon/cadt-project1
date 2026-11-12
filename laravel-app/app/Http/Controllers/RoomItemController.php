<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Services\RoomItemService;
use Illuminate\Http\Request;

class RoomItemController extends Controller
{
    public function __construct(private RoomItemService $items)
    {
    }

    // GET /api/rooms/{room}/items   the things in the room and its key count
    public function index(Room $room)
    {
        return response()->json(['data' => $this->items->show($room)]);
    }

    // PUT /api/rooms/{room}/items   {key_count, items: [{name, quantity, condition, note}]}
    public function update(Request $request, Room $room)
    {
        $data = $request->validate([
            'key_count'         => ['nullable', 'integer', 'min:0', 'max:50'],
            'items'             => ['present', 'array', 'max:' . RoomItemService::MAX_ITEMS],
            'items.*.name'      => ['required', 'string', 'max:100'],
            'items.*.quantity'  => ['required', 'integer', 'min:1', 'max:999'],
            'items.*.condition' => ['nullable', 'string', 'max:50'],
            'items.*.note'      => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json([
            'message' => 'Room items saved.',
            'data'    => $this->items->replace($room, $data['key_count'] ?? null, $data['items']),
        ]);
    }

    // POST /api/rooms/{room}/items/copy   {room_ids: [2, 3]}  the same list for other rooms
    public function copy(Request $request, Room $room)
    {
        $data = $request->validate([
            'room_ids'   => ['required', 'array', 'min:1'],
            'room_ids.*' => ['integer', 'exists:rooms,id'],
        ]);

        $count = $this->items->copy($room, $data['room_ids']);

        return response()->json(['message' => "Copied to {$count} rooms.", 'count' => $count]);
    }
}
