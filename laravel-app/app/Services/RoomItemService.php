<?php

namespace App\Services;

use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * The list of things in each room, and the copy kept on the tenant from the handover day.
 */
class RoomItemService
{
    public const MAX_ITEMS = 40;

    public function show(Room $room): array
    {
        return [
            'key_count' => $room->key_count,
            'items'     => $this->listOf($room),
        ];
    }

    // Replace the whole list (the form always sends every row)
    public function replace(Room $room, ?int $keyCount, array $items): array
    {
        DB::transaction(function () use ($room, $keyCount, $items) {
            $room->update(['key_count' => $keyCount]);
            $room->items()->delete();

            foreach (array_values($items) as $i => $item) {
                $room->items()->create([
                    'name'       => trim($item['name']),
                    'quantity'   => (int) ($item['quantity'] ?? 1),
                    'condition'  => $this->clean($item['condition'] ?? null),
                    'note'       => $this->clean($item['note'] ?? null),
                    'sort_order' => $i,
                ]);
            }
        });

        return $this->show($room->refresh());
    }

    // Give other rooms the same list and key count; returns how many rooms changed
    public function copy(Room $from, array $roomIds): int
    {
        $source = $this->show($from);
        $rooms = Room::whereIn('id', $roomIds)->whereKeyNot($from->id)->get();

        foreach ($rooms as $room) {
            $this->replace($room, $source['key_count'], $source['items']);
        }

        return $rooms->count();
    }

    // Keep the room's list on the tenant, as it is on the handover day
    public function saveHandover(Tenant $tenant): Tenant
    {
        $room = $tenant->room;

        $tenant->update([
            'handover_items'     => $room ? $this->listOf($room) : [],
            'handover_key_count' => $room?->key_count,
            'handover_saved_on'  => today(),
        ]);

        return $tenant;
    }

    private function listOf(Room $room): array
    {
        return $room->items()->get()->map(fn ($item) => [
            'name'      => $item->name,
            'quantity'  => $item->quantity,
            'condition' => $item->condition,
            'note'      => $item->note,
        ])->all();
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
